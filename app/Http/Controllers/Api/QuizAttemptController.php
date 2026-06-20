<?php

namespace App\Http\Controllers\Api;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class QuizAttemptController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $query = QuizAttempt::with([
            'quiz.course',
            'studentProfile.user',
            'studentProfile.batch'
        ]);

        // Scope based on role
        if ($user->hasRole('student')) {
            $studentProfile = StudentProfile::where('user_id', $user->id)->first();
            if ($studentProfile) {
                $query->where('student_profile_id', $studentProfile->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        } elseif ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
            if ($teacherProfile) {
                $query->whereHas('quiz', function ($q) use ($teacherProfile) {
                    $q->where('teacher_profile_id', $teacherProfile->id);
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return response()->json([
            'message' => 'Quiz attempts fetched successfully.',
            'data' => $query->latest()->paginate(20),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quiz_id' => ['required', 'exists:quizzes,id'],
            'student_profile_id' => ['required', 'exists:student_profiles,id'],
        ]);

        // OWNERSHIP CHECK: Student can only start attempts for themselves
        $user = Auth::user();
        if (!$user->hasRole(['super-admin', 'institution-admin', 'teacher'])) {
            $studentProfile = StudentProfile::where('user_id', $user->id)->first();
            if (!$studentProfile || (int) $studentProfile->id !== (int) $validated['student_profile_id']) {
                abort(403, 'Unauthorized: You can only attempt quizzes for yourself.');
            }
        }

        $quiz = Quiz::findOrFail($validated['quiz_id']);

        // TIMER CHECK: Ensure quiz is available
        if ($quiz->available_from && now()->lt($quiz->available_from)) {
            abort(403, 'This quiz is not yet available.');
        }
        if ($quiz->available_until && now()->gt($quiz->available_until)) {
            abort(403, 'This quiz has expired.');
        }

        $lastAttemptNumber = QuizAttempt::where('quiz_id', $validated['quiz_id'])
            ->where('student_profile_id', $validated['student_profile_id'])
            ->max('attempt_number');

        $attempt = QuizAttempt::create([
            'quiz_id' => $validated['quiz_id'],
            'student_profile_id' => $validated['student_profile_id'],
            'attempt_number' => ($lastAttemptNumber ?? 0) + 1,
            'started_at' => now(),
            'total_marks' => $quiz->total_marks,
            'marks_obtained' => 0,
            'percentage' => 0,
            'result_status' => 'pending',
            'status' => 'in_progress',
        ]);

        return response()->json([
            'message' => 'Quiz attempt started successfully.',
            'data' => $attempt->load([
                'quiz.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ], 201);
    }

    public function show(QuizAttempt $quizAttempt): JsonResponse
    {
        $this->authorizeQuizAttemptAccess($quizAttempt);

        return response()->json([
            'message' => 'Quiz attempt fetched successfully.',
            'data' => $quizAttempt->load([
                'quiz.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function update(Request $request, QuizAttempt $quizAttempt): JsonResponse
    {
        // OWNERSHIP CHECK
        $user = Auth::user();
        if (!$user->hasRole(['super-admin', 'institution-admin', 'teacher'])) {
            $studentProfile = StudentProfile::where('user_id', $user->id)->first();
            if (!$studentProfile || (int) $studentProfile->id !== (int) $quizAttempt->student_profile_id) {
                abort(403, 'Unauthorized: You can only update your own quiz attempts.');
            }
        }

        $validated = $request->validate([
            'marks_obtained' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:in_progress,submitted,evaluated,cancelled'],
        ]);

        // TIMER ENFORCEMENT: Check if quiz duration has expired on submit
        if (($validated['status'] ?? null) === 'submitted' && $quizAttempt->quiz->duration_minutes) {
            $startTime = $quizAttempt->started_at;
            $elapsedMinutes = $startTime ? now()->diffInMinutes($startTime) : 0;
            if ($elapsedMinutes > $quizAttempt->quiz->duration_minutes) {
                // Auto-submit with whatever answers they have
                $validated['status'] = 'submitted';
                $validated['submitted_at'] = now();
            }
        }

        if (isset($validated['marks_obtained'])) {
            $totalMarks = $quizAttempt->total_marks > 0 ? $quizAttempt->total_marks : 1;

            $validated['percentage'] = round(($validated['marks_obtained'] / $totalMarks) * 100, 2);

            // FIX: Use PERCENTAGE-based comparison, not raw marks
            $passingMarks = $quizAttempt->quiz->passing_marks ?? 0;
            $passPercentage = $totalMarks > 0 ? ($passingMarks / $totalMarks) * 100 : 0;

            $validated['result_status'] = $validated['percentage'] >= $passPercentage
                ? 'passed'
                : 'failed';
        }

        if (($validated['status'] ?? null) === 'submitted') {
            $validated['submitted_at'] = now();
        }

        if (($validated['status'] ?? null) === 'evaluated' && !$quizAttempt->submitted_at) {
            $validated['submitted_at'] = now();
        }

        $quizAttempt->update($validated);

        return response()->json([
            'message' => 'Quiz attempt updated successfully.',
            'data' => $quizAttempt->fresh()->load([
                'quiz.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function destroy(QuizAttempt $quizAttempt): JsonResponse
    {
        $user = Auth::user();
        if (!$user->hasRole(['super-admin', 'institution-admin'])) {
            abort(403, 'Unauthorized: Only admins can delete quiz attempts.');
        }

        $quizAttempt->delete();

        return response()->json([
            'message' => 'Quiz attempt deleted successfully.',
        ]);
    }

    private function authorizeQuizAttemptAccess(QuizAttempt $quizAttempt): void
    {
        $user = Auth::user();

        if ($user->hasRole(['super-admin', 'institution-admin'])) {
            return;
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
            if ($teacherProfile && $quizAttempt->quiz->teacher_profile_id === $teacherProfile->id) {
                return;
            }
            abort(403, 'Unauthorized: This quiz is not assigned to you.');
        }

        $studentProfile = StudentProfile::where('user_id', $user->id)->first();
        if (!$studentProfile || (int) $studentProfile->id !== (int) $quizAttempt->student_profile_id) {
            abort(403, 'Unauthorized: You can only view your own quiz attempts.');
        }
    }
}
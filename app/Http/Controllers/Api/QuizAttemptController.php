<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstitutionUser;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

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

        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Ownership Check
    |--------------------------------------------------------------------------
    */
        if (!$user->hasAnyRole([
            'super-admin',
            'institution-admin',
            'teacher'
        ])) {

            $studentProfile = StudentProfile::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$studentProfile ||
                (int) $studentProfile->id !==
                (int) $validated['student_profile_id']
            ) {

                abort(
                    403,
                    'Unauthorized: You can only attempt quizzes for yourself.'
                );
            }
        }

        $quiz = Quiz::findOrFail(
            $validated['quiz_id']
        );

        /*
    |--------------------------------------------------------------------------
    | Quiz Availability Check
    |--------------------------------------------------------------------------
    */
        if (
            $quiz->available_from &&
            now()->lt($quiz->available_from)
        ) {

            abort(
                403,
                'This quiz is not yet available.'
            );
        }

        if (
            $quiz->available_until &&
            now()->gt($quiz->available_until)
        ) {

            abort(
                403,
                'This quiz has expired.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Maximum Attempt Check
    |--------------------------------------------------------------------------
    */
        $currentAttempts = QuizAttempt::where(
            'quiz_id',
            $validated['quiz_id']
        )
            ->where(
                'student_profile_id',
                $validated['student_profile_id']
            )
            ->count();

        if (
            $quiz->maximum_attempts &&
            $currentAttempts >= $quiz->maximum_attempts
        ) {

            abort(
                403,
                'Maximum number of attempts reached.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Prevent Duplicate In-Progress Attempt
    |--------------------------------------------------------------------------
    */
        $inProgressAttempt = QuizAttempt::where(
            'quiz_id',
            $validated['quiz_id']
        )
            ->where(
                'student_profile_id',
                $validated['student_profile_id']
            )
            ->where(
                'status',
                'in_progress'
            )
            ->exists();

        if ($inProgressAttempt) {

            abort(
                403,
                'You already have an unfinished attempt.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Next Attempt Number
    |--------------------------------------------------------------------------
    */
        $lastAttemptNumber = QuizAttempt::where(
            'quiz_id',
            $validated['quiz_id']
        )
            ->where(
                'student_profile_id',
                $validated['student_profile_id']
            )
            ->max(
                'attempt_number'
            );

        /*
    |--------------------------------------------------------------------------
    | Create Attempt
    |--------------------------------------------------------------------------
    */
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
        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Ownership Check
    |--------------------------------------------------------------------------
    */
        if (!$user->hasAnyRole([
            'super-admin',
            'institution-admin',
            'teacher'
        ])) {

            $studentProfile = StudentProfile::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$studentProfile ||
                (int)$studentProfile->id !==
                (int)$quizAttempt->student_profile_id
            ) {

                abort(
                    403,
                    'Unauthorized: You can only update your own quiz attempts.'
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Student cannot modify submitted/evaluated attempts
        |--------------------------------------------------------------------------
        */
            if (
                in_array(
                    $quizAttempt->status,
                    [
                        'submitted',
                        'evaluated',
                        'cancelled'
                    ]
                )
            ) {

                abort(
                    403,
                    'This attempt can no longer be modified.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'status' => [
                'nullable',
                'in:in_progress,submitted,evaluated,cancelled'
            ],
            'marks_obtained' => [
                'nullable',
                'numeric',
                'min:0'
            ],
        ]);

        /*
|--------------------------------------------------------------------------
| Teachers/Admins evaluate attempts
|--------------------------------------------------------------------------
*/
        if (
            $user->hasAnyRole([
                'teacher',
                'institution-admin',
                'super-admin'
            ])
        ) {

            /*
    |--------------------------------------------------------------------------
    | Teacher Ownership Check
    |--------------------------------------------------------------------------
    */
            if ($user->hasRole('teacher')) {

                $teacherProfile = TeacherProfile::where(
                    'user_id',
                    $user->id
                )->first();

                if (
                    !$teacherProfile ||
                    $quizAttempt->quiz->teacher_profile_id !== $teacherProfile->id
                ) {

                    abort(
                        403,
                        'Unauthorized: This quiz does not belong to you.'
                    );
                }
            }

            /*
    |--------------------------------------------------------------------------
    | Institution Admin Scope Check
    |--------------------------------------------------------------------------
    */
            if ($user->hasRole('institution-admin')) {

                $institutionUser = InstitutionUser::where(
                    'user_id',
                    $user->id
                )->first();

                if (
                    !$institutionUser ||
                    $quizAttempt->studentProfile->institution_id !==
                    $institutionUser->institution_id
                ) {

                    abort(
                        403,
                        'Unauthorized institution access.'
                    );
                }
            }

            if (isset($validated['marks_obtained'])) {

                /*
        |--------------------------------------------------------------------------
        | Prevent Marks > Total Marks
        |--------------------------------------------------------------------------
        */
                if (
                    $validated['marks_obtained'] >
                    $quizAttempt->total_marks
                ) {

                    abort(
                        422,
                        'Marks obtained cannot exceed total marks.'
                    );
                }

                $totalMarks = max(
                    1,
                    $quizAttempt->total_marks
                );

                $validated['percentage'] = round(
                    ($validated['marks_obtained'] / $totalMarks) * 100,
                    2
                );

                $passingMarks =
                    $quizAttempt->quiz->passing_marks ?? 0;

                $passPercentage = round(
                    ($passingMarks / $totalMarks) * 100,
                    2
                );

                $validated['result_status'] =
                    $validated['percentage'] >= $passPercentage
                    ? 'passed'
                    : 'failed';
            }
        } else {

            /*
    |--------------------------------------------------------------------------
    | Students cannot change marks/result
    |--------------------------------------------------------------------------
    */
            unset(
                $validated['marks_obtained'],
                $validated['percentage'],
                $validated['result_status']
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Submission Timestamp
    |--------------------------------------------------------------------------
    */
        if (
            ($validated['status'] ?? null) === 'submitted'
        ) {

            $validated['submitted_at'] = now();
        }

        /*
|--------------------------------------------------------------------------
| Lock Evaluated Attempts
|--------------------------------------------------------------------------
*/
        if (
            $quizAttempt->status === 'evaluated'
        ) {

            abort(
                403,
                'Evaluated attempts cannot be modified.'
            );
        }

        $quizAttempt->update($validated);

        return response()->json([
            'message' => 'Quiz attempt updated successfully.',
            'data' => $quizAttempt
                ->fresh()
                ->load([
                    'quiz.course',
                    'studentProfile.user',
                    'studentProfile.batch'
                ]),
        ]);
    }

    public function destroy(QuizAttempt $quizAttempt): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user->hasAnyRole([
            'super-admin',
            'institution-admin'
        ])) {

            abort(
                403,
                'Unauthorized: Only admins can delete quiz attempts.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Institution Admin Scope
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$institutionUser ||
                $quizAttempt->studentProfile->institution_id !==
                $institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized institution access.'
                );
            }
        }

        $quizAttempt->delete();

        return response()->json([
            'message' => 'Quiz attempt deleted successfully.',
        ]);
    }

    private function authorizeQuizAttemptAccess(QuizAttempt $quizAttempt): void
    {
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                $institutionUser &&
                $quizAttempt->studentProfile->institution_id ===
                $institutionUser->institution_id
            ) {

                return;
            }

            abort(
                403,
                'Unauthorized institution access.'
            );
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

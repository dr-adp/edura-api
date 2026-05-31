<?php

namespace App\Http\Controllers\Api;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class QuizAttemptController extends Controller
{
    public function index(): JsonResponse
    {
        $attempts = QuizAttempt::with([
            'quiz.course',
            'studentProfile.user',
            'studentProfile.batch'
        ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Quiz attempts fetched successfully.',
            'data' => $attempts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quiz_id' => ['required', 'exists:quizzes,id'],
            'student_profile_id' => ['required', 'exists:student_profiles,id'],
        ]);

        $quiz = Quiz::findOrFail($validated['quiz_id']);

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
        $validated = $request->validate([
            'marks_obtained' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:in_progress,submitted,evaluated,cancelled'],
        ]);

        if (isset($validated['marks_obtained'])) {
            $totalMarks = $quizAttempt->total_marks > 0 ? $quizAttempt->total_marks : 1;

            $validated['percentage'] = round(($validated['marks_obtained'] / $totalMarks) * 100, 2);

            $passingMarks = $quizAttempt->quiz->passing_marks ?? 0;

            $validated['result_status'] = $validated['marks_obtained'] >= $passingMarks
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
        $quizAttempt->delete();

        return response()->json([
            'message' => 'Quiz attempt deleted successfully.',
        ]);
    }
}

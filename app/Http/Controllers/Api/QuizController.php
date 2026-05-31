<?php

namespace App\Http\Controllers\Api;

use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class QuizController extends Controller
{
    public function index(): JsonResponse
    {
        $quizzes = Quiz::with([
            'course',
            'courseSection',
            'lesson',
            'teacherProfile.user'
        ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Quizzes fetched successfully.',
            'data' => $quizzes,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],

            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'total_marks' => ['nullable', 'numeric', 'min:0'],
            'passing_marks' => ['nullable', 'numeric', 'min:0'],

            'shuffle_questions' => ['boolean'],
            'show_result_immediately' => ['boolean'],

            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after:available_from'],

            'status' => ['nullable', 'in:draft,published,closed'],
        ]);

        $quiz = Quiz::create($validated);

        return response()->json([
            'message' => 'Quiz created successfully.',
            'data' => $quiz->load([
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user'
            ]),
        ], 201);
    }

    public function show(Quiz $quiz): JsonResponse
    {
        return response()->json([
            'message' => 'Quiz fetched successfully.',
            'data' => $quiz->load([
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user'
            ]),
        ]);
    }

    public function update(Request $request, Quiz $quiz): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['sometimes', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],

            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'total_marks' => ['nullable', 'numeric', 'min:0'],
            'passing_marks' => ['nullable', 'numeric', 'min:0'],

            'shuffle_questions' => ['boolean'],
            'show_result_immediately' => ['boolean'],

            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after:available_from'],

            'status' => ['nullable', 'in:draft,published,closed'],
        ]);

        $quiz->update($validated);

        return response()->json([
            'message' => 'Quiz updated successfully.',
            'data' => $quiz->fresh()->load([
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user'
            ]),
        ]);
    }

    public function destroy(Quiz $quiz): JsonResponse
    {
        $quiz->delete();

        return response()->json([
            'message' => 'Quiz deleted successfully.',
        ]);
    }
}

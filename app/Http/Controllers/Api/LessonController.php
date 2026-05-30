<?php

namespace App\Http\Controllers\Api;

use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class LessonController extends Controller
{
    public function index(): JsonResponse
    {
        $lessons = Lesson::with([
            'course',
            'courseSection'
        ])
            ->orderBy('sort_order')
            ->paginate(20);

        return response()->json([
            'message' => 'Lessons fetched successfully.',
            'data' => $lessons,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],

            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],

            'lesson_type' => ['nullable', 'in:text,video,pdf,mixed'],

            'video_url' => ['nullable', 'string', 'max:500'],
            'pdf_url' => ['nullable', 'string', 'max:500'],
            'external_resource_url' => ['nullable', 'string', 'max:500'],

            'duration_minutes' => ['nullable', 'integer', 'min:1'],

            'is_preview' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],

            'status' => ['nullable', 'in:draft,published,archived'],
        ]);

        $lesson = Lesson::create($validated);

        return response()->json([
            'message' => 'Lesson created successfully.',
            'data' => $lesson->load([
                'course',
                'courseSection'
            ]),
        ], 201);
    }

    public function show(Lesson $lesson): JsonResponse
    {
        return response()->json([
            'message' => 'Lesson fetched successfully.',
            'data' => $lesson->load([
                'course',
                'courseSection'
            ]),
        ]);
    }

    public function update(Request $request, Lesson $lesson): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['sometimes', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],

            'title' => ['sometimes', 'string', 'max:255'],
            'short_description' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],

            'lesson_type' => ['nullable', 'in:text,video,pdf,mixed'],

            'video_url' => ['nullable', 'string', 'max:500'],
            'pdf_url' => ['nullable', 'string', 'max:500'],
            'external_resource_url' => ['nullable', 'string', 'max:500'],

            'duration_minutes' => ['nullable', 'integer', 'min:1'],

            'is_preview' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],

            'status' => ['nullable', 'in:draft,published,archived'],
        ]);

        $lesson->update($validated);

        return response()->json([
            'message' => 'Lesson updated successfully.',
            'data' => $lesson->load([
                'course',
                'courseSection'
            ]),
        ]);
    }

    public function destroy(Lesson $lesson): JsonResponse
    {
        $lesson->delete();

        return response()->json([
            'message' => 'Lesson deleted successfully.',
        ]);
    }
}

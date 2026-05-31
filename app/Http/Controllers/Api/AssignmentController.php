<?php

namespace App\Http\Controllers\Api;

use App\Models\Assignment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class AssignmentController extends Controller
{
    public function index(): JsonResponse
    {
        $assignments = Assignment::with([
            'course',
            'courseSection',
            'lesson',
            'teacherProfile.user'
        ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Assignments fetched successfully.',
            'data' => $assignments,
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

            'short_description' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],

            'maximum_marks' => ['nullable', 'numeric', 'min:0'],

            'available_from' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],

            'allow_late_submission' => ['boolean'],

            'status' => ['nullable', 'in:draft,published,closed'],
        ]);

        $assignment = Assignment::create($validated);

        return response()->json([
            'message' => 'Assignment created successfully.',
            'data' => $assignment->load([
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user'
            ]),
        ], 201);
    }

    public function show(Assignment $assignment): JsonResponse
    {
        return response()->json([
            'message' => 'Assignment fetched successfully.',
            'data' => $assignment->load([
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user'
            ]),
        ]);
    }

    public function update(Request $request, Assignment $assignment): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['sometimes', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],

            'title' => ['sometimes', 'string', 'max:255'],

            'short_description' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],

            'maximum_marks' => ['nullable', 'numeric', 'min:0'],

            'available_from' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],

            'allow_late_submission' => ['boolean'],

            'status' => ['nullable', 'in:draft,published,closed'],
        ]);

        $assignment->update($validated);

        return response()->json([
            'message' => 'Assignment updated successfully.',
            'data' => $assignment->fresh()->load([
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user'
            ]),
        ]);
    }

    public function destroy(Assignment $assignment): JsonResponse
    {
        $assignment->delete();

        return response()->json([
            'message' => 'Assignment deleted successfully.',
        ]);
    }
}

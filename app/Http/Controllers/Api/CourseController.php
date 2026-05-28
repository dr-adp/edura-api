<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class CourseController extends Controller
{
    public function index(): JsonResponse
    {
        $courses = Course::with([
            'institution',
            'department',
            'batch',
            'teacherProfile.user'
        ])
            ->latest()
            ->paginate(10);

        return response()->json([
            'message' => 'Courses fetched successfully.',
            'data' => $courses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'batch_id' => ['nullable', 'exists:batches,id'],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],

            'title' => ['required', 'string', 'max:255'],

            'short_description' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],

            'price' => ['nullable', 'numeric', 'min:0'],

            'course_type' => ['nullable', 'in:free,paid,private'],
            'level' => ['nullable', 'in:beginner,intermediate,advanced'],

            'language' => ['nullable', 'string', 'max:100'],
            'duration_hours' => ['nullable', 'integer', 'min:1'],

            'certificate_enabled' => ['boolean'],
            'live_class_enabled' => ['boolean'],

            'status' => ['nullable', 'in:draft,published,archived'],
        ]);

        $validated['slug'] = Str::slug($validated['title']) . '-' . time();

        $course = Course::create($validated);

        return response()->json([
            'message' => 'Course created successfully.',
            'data' => $course->load([
                'institution',
                'department',
                'batch',
                'teacherProfile.user'
            ]),
        ], 201);
    }

    public function show(Course $course): JsonResponse
    {
        return response()->json([
            'message' => 'Course fetched successfully.',
            'data' => $course->load([
                'institution',
                'department',
                'batch',
                'teacherProfile.user'
            ]),
        ]);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'batch_id' => ['nullable', 'exists:batches,id'],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],

            'title' => ['sometimes', 'required', 'string', 'max:255'],

            'short_description' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],

            'price' => ['nullable', 'numeric', 'min:0'],

            'course_type' => ['nullable', 'in:free,paid,private'],
            'level' => ['nullable', 'in:beginner,intermediate,advanced'],

            'language' => ['nullable', 'string', 'max:100'],
            'duration_hours' => ['nullable', 'integer', 'min:1'],

            'certificate_enabled' => ['boolean'],
            'live_class_enabled' => ['boolean'],

            'status' => ['nullable', 'in:draft,published,archived'],
        ]);

        if (isset($validated['title'])) {
            $validated['slug'] = Str::slug($validated['title']) . '-' . time();
        }

        $course->update($validated);

        return response()->json([
            'message' => 'Course updated successfully.',
            'data' => $course->load([
                'institution',
                'department',
                'batch',
                'teacherProfile.user'
            ]),
        ]);
    }

    public function destroy(Course $course): JsonResponse
    {
        $course->delete();

        return response()->json([
            'message' => 'Course deleted successfully.',
        ]);
    }
}

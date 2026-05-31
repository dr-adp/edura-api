<?php

namespace App\Http\Controllers\Api;

use App\Models\CourseEnrollment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

class CourseEnrollmentController extends Controller
{
    public function index(): JsonResponse
    {
        $enrollments = CourseEnrollment::with([
            'course',
            'studentProfile.user',
            'studentProfile.institution',
            'studentProfile.batch'
        ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Course enrollments fetched successfully.',
            'data' => $enrollments,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],

            'student_profile_id' => [
                'required',
                'exists:student_profiles,id',
                Rule::unique('course_enrollments', 'student_profile_id')
                    ->where('course_id', $request->course_id),
            ],

            'enrollment_date' => ['nullable', 'date'],
            'payment_status' => ['nullable', 'in:free,pending,paid,failed,refunded'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'progress_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'in:active,completed,cancelled,expired'],
            'completed_at' => ['nullable', 'date'],
        ]);

        $validated['enrollment_date'] = $validated['enrollment_date'] ?? now()->toDateString();

        $enrollment = CourseEnrollment::create($validated);

        return response()->json([
            'message' => 'Student enrolled in course successfully.',
            'data' => $enrollment->load([
                'course',
                'studentProfile.user',
                'studentProfile.institution',
                'studentProfile.batch'
            ]),
        ], 201);
    }

    public function show(CourseEnrollment $courseEnrollment): JsonResponse
    {
        return response()->json([
            'message' => 'Course enrollment fetched successfully.',
            'data' => $courseEnrollment->load([
                'course',
                'studentProfile.user',
                'studentProfile.institution',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function update(Request $request, CourseEnrollment $courseEnrollment): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['sometimes', 'exists:courses,id'],

            'student_profile_id' => [
                'sometimes',
                'exists:student_profiles,id',
                Rule::unique('course_enrollments', 'student_profile_id')
                    ->where('course_id', $request->course_id ?? $courseEnrollment->course_id)
                    ->ignore($courseEnrollment->id),
            ],

            'enrollment_date' => ['nullable', 'date'],
            'payment_status' => ['nullable', 'in:free,pending,paid,failed,refunded'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'progress_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'in:active,completed,cancelled,expired'],
            'completed_at' => ['nullable', 'date'],
        ]);

        if (($validated['progress_percentage'] ?? null) == 100) {
            $validated['status'] = 'completed';
            $validated['completed_at'] = now();
        }

        $courseEnrollment->update($validated);

        return response()->json([
            'message' => 'Course enrollment updated successfully.',
            'data' => $courseEnrollment->fresh()->load([
                'course',
                'studentProfile.user',
                'studentProfile.institution',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function destroy(CourseEnrollment $courseEnrollment): JsonResponse
    {
        $courseEnrollment->delete();

        return response()->json([
            'message' => 'Course enrollment deleted successfully.',
        ]);
    }
}

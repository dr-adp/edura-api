<?php

namespace App\Http\Controllers\Api;

use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\CourseEnrollment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

class LessonProgressController extends Controller
{
    public function index(): JsonResponse
    {
        $progress = LessonProgress::with([
            'courseEnrollment.course',
            'courseEnrollment.studentProfile.user',
            'lesson'
        ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Lesson progress records fetched successfully.',
            'data' => $progress,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_enrollment_id' => ['required', 'exists:course_enrollments,id'],
            'lesson_id' => [
                'required',
                'exists:lessons,id',
                Rule::unique('lesson_progress', 'lesson_id')
                    ->where('course_enrollment_id', $request->course_enrollment_id),
            ],
            'status' => ['nullable', 'in:not_started,in_progress,completed'],
            'progress_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'watch_time_minutes' => ['nullable', 'integer', 'min:0'],
        ]);

        $validated['status'] = $validated['status'] ?? 'in_progress';
        $validated['progress_percentage'] = $validated['progress_percentage'] ?? 0;

        if ($validated['status'] === 'in_progress') {
            $validated['started_at'] = now();
        }

        if ($validated['status'] === 'completed') {
            $validated['progress_percentage'] = 100;
            $validated['completed_at'] = now();
        }

        $lessonProgress = LessonProgress::create($validated);

        $this->recalculateCourseEnrollmentProgress($lessonProgress->courseEnrollment);

        return response()->json([
            'message' => 'Lesson progress created successfully.',
            'data' => $lessonProgress->load([
                'courseEnrollment.course',
                'courseEnrollment.studentProfile.user',
                'lesson'
            ]),
        ], 201);
    }

    public function show(LessonProgress $lessonProgress): JsonResponse
    {
        return response()->json([
            'message' => 'Lesson progress fetched successfully.',
            'data' => $lessonProgress->load([
                'courseEnrollment.course',
                'courseEnrollment.studentProfile.user',
                'lesson'
            ]),
        ]);
    }

    public function update(Request $request, LessonProgress $lessonProgress): JsonResponse
    {
        $validated = $request->validate([
            'course_enrollment_id' => ['sometimes', 'exists:course_enrollments,id'],
            'lesson_id' => [
                'sometimes',
                'exists:lessons,id',
                Rule::unique('lesson_progress', 'lesson_id')
                    ->where('course_enrollment_id', $request->course_enrollment_id ?? $lessonProgress->course_enrollment_id)
                    ->ignore($lessonProgress->id),
            ],
            'status' => ['nullable', 'in:not_started,in_progress,completed'],
            'progress_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'watch_time_minutes' => ['nullable', 'integer', 'min:0'],
        ]);

        if (($validated['status'] ?? null) === 'in_progress' && !$lessonProgress->started_at) {
            $validated['started_at'] = now();
        }

        if (($validated['status'] ?? null) === 'completed') {
            $validated['progress_percentage'] = 100;
            $validated['completed_at'] = now();
        }

        $lessonProgress->update($validated);

        $this->recalculateCourseEnrollmentProgress($lessonProgress->fresh()->courseEnrollment);

        return response()->json([
            'message' => 'Lesson progress updated successfully.',
            'data' => $lessonProgress->fresh()->load([
                'courseEnrollment.course',
                'courseEnrollment.studentProfile.user',
                'lesson'
            ]),
        ]);
    }

    public function destroy(LessonProgress $lessonProgress): JsonResponse
    {
        $enrollment = $lessonProgress->courseEnrollment;

        $lessonProgress->delete();

        $this->recalculateCourseEnrollmentProgress($enrollment);

        return response()->json([
            'message' => 'Lesson progress deleted successfully.',
        ]);
    }

    private function recalculateCourseEnrollmentProgress(CourseEnrollment $courseEnrollment): void
    {
        $course = $courseEnrollment->course;

        $totalLessons = Lesson::where('course_id', $course->id)
            ->where('status', 'published')
            ->count();

        if ($totalLessons === 0) {
            $courseEnrollment->update([
                'progress_percentage' => 0,
                'status' => 'active',
                'completed_at' => null,
            ]);

            return;
        }

        $completedLessons = LessonProgress::where(
            'course_enrollment_id',
            $courseEnrollment->id
        )
            ->where('status', 'completed')
            ->distinct('lesson_id')
            ->count('lesson_id');

        $percentage = min(
            100,
            round(($completedLessons / $totalLessons) * 100, 2)
        );

        $courseEnrollment->update([
            'progress_percentage' => $percentage,
            'status' => $percentage >= 100 ? 'completed' : 'active',
            'completed_at' => $percentage >= 100 ? now() : null,
        ]);
    }
}

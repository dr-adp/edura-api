<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class StudentLearningController extends Controller
{
    /**
     * Return the published lessons for a course that the
     * authenticated student is actively/completely enrolled in.
     */
    public function lessons(Course $course): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $studentProfile = StudentProfile::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        abort_unless(
            $studentProfile,
            403,
            'Unauthorized: Student profile not found.'
        );

        $enrollmentExists = CourseEnrollment::where(
            'course_id',
            $course->id
        )
            ->where(
                'student_profile_id',
                $studentProfile->id
            )
            ->whereIn('status', ['active', 'completed'])
            ->exists();

        abort_unless(
            $enrollmentExists,
            403,
            'Unauthorized: You are not enrolled in this course.'
        );

        $lessons = $course->lessons()
            ->where('status', 'published')
            ->with([
                'courseSection',
                'resources' => function ($query) {
                    $query
                        ->where('status', 'active')
                        ->orderBy('sort_order');
                },
            ])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'message' => 'Course lessons fetched successfully.',
            'data' => $lessons,
        ]);
    }

    /**
     * Return one published lesson for a course in which
     * the authenticated student is enrolled.
     */
    public function showLesson(
        Course $course,
        int $lesson
    ): JsonResponse {
        /** @var User $user */
        $user = Auth::user();

        $studentProfile = StudentProfile::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        abort_unless(
            $studentProfile,
            403,
            'Unauthorized: Student profile not found.'
        );

        $enrollmentExists = CourseEnrollment::where(
            'course_id',
            $course->id
        )
            ->where(
                'student_profile_id',
                $studentProfile->id
            )
            ->whereIn('status', ['active', 'completed'])
            ->exists();

        abort_unless(
            $enrollmentExists,
            403,
            'Unauthorized: You are not enrolled in this course.'
        );

        $lessonModel = $course->lessons()
            ->whereKey($lesson)
            ->where('status', 'published')
            ->with([
                'courseSection',
                'resources' => function ($query) {
                    $query
                        ->where('status', 'active')
                        ->orderBy('sort_order');
                },
            ])
            ->firstOrFail();

        return response()->json([
            'message' => 'Lesson fetched successfully.',
            'data' => $lessonModel,
        ]);
    }

    /**
     * Return published resources for a published lesson
     * in a course the authenticated student is enrolled in.
     */
    public function resources(
        Course $course,
        int $lesson
    ): JsonResponse {
        /** @var User $user */
        $user = Auth::user();

        $studentProfile = StudentProfile::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        abort_unless(
            $studentProfile,
            403,
            'Unauthorized: Student profile not found.'
        );

        $enrollmentExists = CourseEnrollment::where(
            'course_id',
            $course->id
        )
            ->where(
                'student_profile_id',
                $studentProfile->id
            )
            ->whereIn('status', ['active', 'completed'])
            ->exists();

        abort_unless(
            $enrollmentExists,
            403,
            'Unauthorized: You are not enrolled in this course.'
        );

        $lessonModel = $course->lessons()
            ->whereKey($lesson)
            ->where('status', 'published')
            ->firstOrFail();

        $resources = $lessonModel->resources()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'message' => 'Lesson resources fetched successfully.',
            'data' => $resources,
        ]);
    }
}

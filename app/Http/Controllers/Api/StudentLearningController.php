<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
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

        $this->ensureCourseAndStudentShareInstitution($course, $studentProfile);

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

        $lessons->each(function (Lesson $lesson) use ($course) {
            $this->clearStaleCourseSection($lesson, $course);
        });

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

        $this->ensureCourseAndStudentShareInstitution($course, $studentProfile);

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

        $this->clearStaleCourseSection($lessonModel, $course);

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

        $this->ensureCourseAndStudentShareInstitution($course, $studentProfile);

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

    private function clearStaleCourseSection(Lesson $lesson, Course $course): void
    {
        $section = $lesson->courseSection;

        if (
            $lesson->course_section_id !== null &&
            (
                ! $section ||
                (int) $section->course_id !== (int) $course->id
            )
        ) {
            $lesson->setAttribute('course_section_id', null);
            $lesson->setRelation('courseSection', null);
        }
    }

    private function ensureCourseAndStudentShareInstitution(
        Course $course,
        StudentProfile $studentProfile
    ): void {
        abort_unless(
            $course->institution_id !== null &&
                $studentProfile->institution_id !== null &&
                (int) $course->institution_id === (int) $studentProfile->institution_id,
            403,
            'Unauthorized: Course and student must belong to the same institution.'
        );
    }
}

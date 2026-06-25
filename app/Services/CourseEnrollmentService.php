<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use Illuminate\Support\Facades\Auth;

class CourseEnrollmentService
{
    public function create(array $validated): CourseEnrollment
    {
        $course = Course::findOrFail(
            $validated['course_id']
        );

        $studentProfile = StudentProfile::findOrFail(
            $validated['student_profile_id']
        );

        $user = Auth::user();

        if ($user->hasRole('teacher')) {

            $teacherProfile = TeacherProfile::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$teacherProfile ||
                (int) $course->teacher_profile_id !==
                (int) $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized teacher access.'
                );
            }
        }

        if ($user->hasRole('student')) {

            $currentStudent = StudentProfile::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$currentStudent ||
                (int) $currentStudent->id !==
                (int) $studentProfile->id
            ) {

                abort(
                    403,
                    'You may only enroll yourself.'
                );
            }
        }

        if (
            isset($course->status) &&
            $course->status !== 'published'
        ) {

            abort(
                422,
                'Course is not available for enrollment.'
            );
        }

        return CourseEnrollment::create(
            $validated
        );
    }
}

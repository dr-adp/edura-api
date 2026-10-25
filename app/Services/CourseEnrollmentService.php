<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
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

        $this->ensureCourseAndStudentShareInstitution($course, $studentProfile);

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

        // Progress and completion are derived from lesson-progress records.
        $validated['progress_percentage'] = 0;
        $validated['completed_at'] = null;

        if (($validated['status'] ?? null) === 'completed') {
            $validated['status'] = 'active';
        }

        $enrollment = CourseEnrollment::create($validated);
        $this->recalculateProgress($enrollment);

        return $enrollment->fresh();
    }

    public function ensureCourseAndStudentShareInstitution(
        Course $course,
        StudentProfile $studentProfile
    ): void {
        if ((int) $course->institution_id !== (int) $studentProfile->institution_id) {
            abort(
                422,
                'Course and student must belong to the same institution.'
            );
        }
    }

    public function recalculateProgress(CourseEnrollment $courseEnrollment): void
    {
        $courseEnrollment->loadMissing('course');

        $totalLessons = Lesson::where('course_id', $courseEnrollment->course_id)
            ->where('status', 'published')
            ->count();

        $completedLessons = $totalLessons === 0
            ? 0
            : LessonProgress::where('course_enrollment_id', $courseEnrollment->id)
                ->where('status', 'completed')
                ->whereHas('lesson', function ($lessonQuery) use ($courseEnrollment) {
                    $lessonQuery
                        ->where('course_id', $courseEnrollment->course_id)
                        ->where('status', 'published');
                })
                ->distinct('lesson_id')
                ->count('lesson_id');

        $percentage = $totalLessons === 0
            ? 0
            : min(100, round(($completedLessons / $totalLessons) * 100, 2));

        $status = match ($courseEnrollment->status) {
            'cancelled', 'expired' => $courseEnrollment->status,
            default => $percentage >= 100 ? 'completed' : 'active',
        };

        $courseEnrollment->update([
            'progress_percentage' => $percentage,
            'status' => $status,
            'completed_at' => $status === 'completed'
                ? ($courseEnrollment->completed_at ?? now())
                : null,
        ]);
    }

    public function recalculateCourseEnrollments(int $courseId): void
    {
        CourseEnrollment::where('course_id', $courseId)
            ->each(function (CourseEnrollment $courseEnrollment) {
                $this->recalculateProgress($courseEnrollment);
            });
    }
}

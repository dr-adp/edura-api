<?php

namespace App\Services;

use App\Exceptions\AssessmentLockSetChanged;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class CourseEnrollmentService
{
    public function __construct(
        private CourseAssessmentLockService $courseAssessmentLockService
    ) {
    }

    /**
     * Create an enrollment under its course, student, and unique pair locks.
     *
     * The optional callback lets the API controller repeat its role-specific
     * management check against the freshly locked course and student rows.
     */
    public function create(array $validated, ?callable $authorize = null): CourseEnrollment
    {
        return $this->courseAssessmentLockService->transactionWithLockSetRetries(
            function () use ($validated, $authorize): CourseEnrollment {
                $courseId = (int) $validated['course_id'];
                $studentProfileId = (int) $validated['student_profile_id'];

                $course = $this->courseAssessmentLockService->lockCourse(
                    $courseId,
                    withTrashed: false
                );
                $student = $this->courseAssessmentLockService
                    ->lockStudent($studentProfileId);
                $existingEnrollment = $this->courseAssessmentLockService
                    ->lockCourseEnrollments([[
                        'course_id' => $courseId,
                        'student_profile_id' => $studentProfileId,
                    ]])->first();

                if ($existingEnrollment) {
                    throw ValidationException::withMessages([
                        'student_profile_id' => 'The student is already enrolled in this course.',
                    ]);
                }

                $this->ensureCourseAndStudentShareInstitution($course, $student);
                $this->authorizeEnrollmentCreator($course, $student);

                if ($authorize !== null) {
                    $authorize($course, $student);
                }

                if (isset($course->status) && $course->status !== 'published') {
                    abort(422, 'Course is not available for enrollment.');
                }

                // Progress and completion are derived from lesson-progress records.
                $attributes = $validated;
                $attributes['progress_percentage'] = 0;
                $attributes['completed_at'] = null;

                if (($attributes['status'] ?? null) === 'completed') {
                    $attributes['status'] = 'active';
                }

                $enrollment = CourseEnrollment::create($attributes);
                $this->recalculateProgressWithinTransaction($enrollment);

                return $enrollment->fresh();
            }
        );
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

    /**
     * Lock an existing enrollment's current parents and recompute its progress.
     * This wrapper joins an existing transaction and retries only after a full
     * rollback if the enrollment changed pairs while locks were acquired.
     */
    public function recalculateProgress(CourseEnrollment $courseEnrollment): void
    {
        $enrollmentId = (int) $courseEnrollment->getKey();

        $this->courseAssessmentLockService->transactionWithLockSetRetries(
            function () use ($enrollmentId): void {
                $identity = CourseEnrollment::query()
                    ->whereKey($enrollmentId)
                    ->firstOrFail();
                $courseId = (int) $identity->course_id;
                $studentProfileId = (int) $identity->student_profile_id;

                $this->courseAssessmentLockService->lockCourses([$courseId]);
                $this->courseAssessmentLockService->lockStudents([$studentProfileId]);
                $lockedEnrollment = $this->courseAssessmentLockService->lockCourseEnrollments([[
                    'course_id' => $courseId,
                    'student_profile_id' => $studentProfileId,
                ]])->first(fn (CourseEnrollment $candidate): bool =>
                    (int) $candidate->id === $enrollmentId
                );

                if (! $lockedEnrollment) {
                    $currentEnrollment = CourseEnrollment::withTrashed()
                        ->whereKey($enrollmentId)
                        ->first();

                    if (! $currentEnrollment) {
                        abort(404);
                    }

                    throw new AssessmentLockSetChanged(
                        [$courseId, (int) $currentEnrollment->course_id],
                        [$studentProfileId, (int) $currentEnrollment->student_profile_id]
                    );
                }

                if (
                    (int) $lockedEnrollment->course_id !== $courseId ||
                    (int) $lockedEnrollment->student_profile_id !== $studentProfileId ||
                    $lockedEnrollment->trashed()
                ) {
                    throw new AssessmentLockSetChanged(
                        [$courseId, (int) $lockedEnrollment->course_id],
                        [$studentProfileId, (int) $lockedEnrollment->student_profile_id]
                    );
                }

                $this->recalculateProgressWithinTransaction($lockedEnrollment);
            }
        );
    }

    /**
     * Recompute progress while the caller already holds the course, student,
     * and enrollment row locks.
     */
    public function recalculateProgressWithinTransaction(
        CourseEnrollment $courseEnrollment
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Enrollment progress recalculation must participate in an active transaction.'
            );
        }

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

    /** Lock a course and every current enrollment in canonical parent order. */
    public function recalculateCourseEnrollments(int $courseId): void
    {
        $this->courseAssessmentLockService->transactionWithLockSetRetries(
            function () use ($courseId): void {
                $this->courseAssessmentLockService->lockCourses([$courseId]);

                $identities = CourseEnrollment::query()
                    ->where('course_id', $courseId)
                    ->orderBy('id')
                    ->get(['id', 'course_id', 'student_profile_id', 'deleted_at']);
                $studentProfileIds = $identities
                    ->pluck('student_profile_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
                $this->courseAssessmentLockService->lockStudents($studentProfileIds);

                $pairs = $identities
                    ->map(static fn (CourseEnrollment $enrollment): array => [
                        'course_id' => (int) $enrollment->course_id,
                        'student_profile_id' => (int) $enrollment->student_profile_id,
                    ])
                    ->all();
                $locked = $this->courseAssessmentLockService
                    ->lockCourseEnrollments($pairs);

                if ($this->enrollmentIdentities($identities) !== $this->enrollmentIdentities($locked)) {
                    throw new AssessmentLockSetChanged(
                        [$courseId],
                        array_merge($studentProfileIds, $locked->pluck('student_profile_id')->all())
                    );
                }

                foreach ($locked as $enrollment) {
                    if ((int) $enrollment->course_id !== $courseId || $enrollment->trashed()) {
                        throw new AssessmentLockSetChanged(
                            [$courseId, (int) $enrollment->course_id],
                            [$enrollment->student_profile_id]
                        );
                    }

                    $this->recalculateProgressWithinTransaction($enrollment);
                }
            }
        );
    }

    private function authorizeEnrollmentCreator(Course $course, StudentProfile $studentProfile): void
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();

            if (! $teacherProfile || (int) $course->teacher_profile_id !== (int) $teacherProfile->id) {
                abort(403, 'Unauthorized teacher access.');
            }
        }

        if ($user->hasRole('student')) {
            $currentStudent = StudentProfile::where('user_id', $user->id)->first();

            if (! $currentStudent || (int) $currentStudent->id !== (int) $studentProfile->id) {
                abort(403, 'You may only enroll yourself.');
            }
        }
    }

    /** @return list<array{int, int, int, string|null}> */
    private function enrollmentIdentities($enrollments): array
    {
        return $enrollments->map(static fn (CourseEnrollment $enrollment): array => [
            (int) $enrollment->id,
            (int) $enrollment->course_id,
            (int) $enrollment->student_profile_id,
            $enrollment->deleted_at?->toDateTimeString(),
        ])->sort()->values()->all();
    }
}

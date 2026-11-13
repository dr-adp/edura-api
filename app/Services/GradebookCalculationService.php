<?php

namespace App\Services;

use App\Exceptions\AssessmentLockSetChanged;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Gradebook;
use App\Models\QuizAttempt;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class GradebookCalculationService
{
    private const VALID_ENROLLMENT_STATUSES = [
        'active',
        'completed',
    ];

    public function __construct(
        private QuizAttemptGradingService $quizAttemptGradingService,
        private CourseAssessmentLockService $courseAssessmentLockService
    ) {
    }

    public function recalculate(int $courseId, int $studentProfileId): Gradebook
    {
        if (DB::transactionLevel() > 0) {
            return $this->recalculateWithinTransaction($courseId, $studentProfileId);
        }

        // Retry only a changed lock set, and only after the previous outer
        // transaction has rolled back. Deadlock retries remain owned by the
        // caller so nested transactions never retry independently.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(
                    fn (): Gradebook => $this->recalculateWithinTransaction(
                        $courseId,
                        $studentProfileId
                    ),
                    attempts: 1
                );
            } catch (AssessmentLockSetChanged $exception) {
                if ($attempt >= 3) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Recalculate inside the caller's transaction without opening a nested
     * transaction or committing any caller-owned locks.
     */
    public function recalculateWithinTransaction(
        int $courseId,
        int $studentProfileId
    ): Gradebook {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Gradebook recalculation must participate in an active transaction.'
            );
        }

        $enrollment = $this->courseAssessmentLockService
            ->lockCourseStudentAndEnrollment($courseId, $studentProfileId);

        // These locking reads revalidate current institution relationships
        // even when the caller's transaction uses repeatable-read snapshots.
        $course = $this->courseAssessmentLockService->lockCourse(
            $courseId,
            withTrashed: false
        );
        $student = $this->courseAssessmentLockService->lockStudent($studentProfileId);

        if ((int) $course->institution_id !== (int) $student->institution_id) {
            throw ValidationException::withMessages([
                'student_profile_id' => 'Student and Course must belong to the same institution.',
            ]);
        }

        $this->ensureStudentEnrolledInCourse(
            $courseId,
            $studentProfileId,
            $course,
            $student,
            $enrollment
        );

        $lockedInputs = $this->courseAssessmentLockService
            ->lockGradebookCalculationInputs($courseId, $studentProfileId);

        $assignmentEvaluations = $lockedInputs['assignment_evaluations'];

        $assignmentMarks = $assignmentEvaluations->sum('marks_obtained');
        $assignmentMaximumMarks = $assignmentEvaluations->sum('maximum_marks');

        $courseQuizAttempts = $lockedInputs['quiz_attempts'];

        $hasPendingManualGrading = $courseQuizAttempts->contains(
            fn (QuizAttempt $attempt) => $this->quizAttemptGradingService
                ->hasPendingManualAnswers($attempt)
        );
        $quizAttempts = $courseQuizAttempts->filter(
            fn (QuizAttempt $attempt) =>
                $attempt->status === 'evaluated' &&
                ! $this->quizAttemptGradingService->hasPendingManualAnswers($attempt)
        );

        $quizMarks = $quizAttempts->sum('marks_obtained');
        $quizMaximumMarks = $quizAttempts->sum('total_marks');

        $totalMarks = $assignmentMarks + $quizMarks;
        $maximumMarks = $assignmentMaximumMarks + $quizMaximumMarks;
        $percentage = $this->percentageForMarks(
            (float) $totalMarks,
            (float) $maximumMarks
        );

        $gradebookValues = [
            'assignment_marks' => $assignmentMarks,
            'quiz_marks' => $quizMarks,
            'total_marks' => $totalMarks,
            'maximum_marks' => $maximumMarks,
            'percentage' => $percentage,
            'grade' => $this->gradeForPercentage((float) $percentage),
            'result_status' => $hasPendingManualGrading
                ? 'pending'
                : ((float) $maximumMarks > 0
                    ? $this->resultStatusForPercentage((float) $percentage)
                    : 'pending'),
        ];

        // Save the row returned by the current locking read when it exists.
        // This avoids an older repeatable-read snapshot hiding a row that was
        // committed before we acquired its lock. A soft-deleted row is the
        // existing derived gradebook for this unique pair, so restore that
        // same row rather than attempting a conflicting insert. Certificates
        // retain their own immutable grade snapshots and keep the same FK.
        $lockedGradebook = $lockedInputs['gradebook'];

        if ($lockedGradebook) {
            $lockedGradebook->fill($gradebookValues);

            if ($lockedGradebook->trashed()) {
                if (! $lockedGradebook->restore()) {
                    throw new LogicException(
                        'The soft-deleted gradebook could not be restored during recalculation.'
                    );
                }
            } else {
                $lockedGradebook->save();
            }

            return $lockedGradebook;
        }

        return Gradebook::updateOrCreate(
            [
                'course_id' => $courseId,
                'student_profile_id' => $studentProfileId,
            ],
            $gradebookValues
        );
    }

    public function gradeForPercentage(float $percentage): string
    {
        return match (true) {
            $percentage >= 90 => 'A+',
            $percentage >= 80 => 'A',
            $percentage >= 70 => 'B+',
            $percentage >= 60 => 'B',
            $percentage >= 50 => 'C',
            $percentage >= 40 => 'D',
            default => 'F',
        };
    }

    public function percentageForMarks(float $totalMarks, float $maximumMarks): float
    {
        return $maximumMarks > 0
            ? round(($totalMarks / $maximumMarks) * 100, 2)
            : 0;
    }

    public function resultStatusForPercentage(float $percentage): string
    {
        return $percentage >= 40 ? 'passed' : 'failed';
    }

    private function ensureStudentEnrolledInCourse(
        int $courseId,
        int $studentProfileId,
        Course $course,
        StudentProfile $student,
        ?CourseEnrollment $enrollment
    ): void
    {
        $enrolled = $enrollment &&
            ! $enrollment->trashed() &&
            (int) $enrollment->course_id === $courseId &&
            (int) $enrollment->student_profile_id === $studentProfileId &&
            in_array($enrollment->status, self::VALID_ENROLLMENT_STATUSES, true) &&
            $course->institution_id !== null &&
            $student->institution_id !== null &&
            (int) $course->institution_id === (int) $student->institution_id;

        if (! $enrolled) {
            throw ValidationException::withMessages([
                'student_profile_id' => 'Student is not enrolled in this course.',
            ]);
        }
    }
}

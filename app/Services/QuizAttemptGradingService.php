<?php

namespace App\Services;

use App\Models\Gradebook;
use App\Models\Certificate;
use App\Models\QuestionBank;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class QuizAttemptGradingService
{
    public const PENDING_MANUAL_ANSWERS_ATTRIBUTE = 'has_pending_manual_answers';
    public const PENDING_MANUAL_GRADING_ATTRIBUTE = 'has_pending_manual_grading';

    public function isPendingManualAnswer(QuizAnswer $answer): bool
    {
        return in_array(
            $answer->questionBank?->question_type,
            QuestionBank::MANUAL_QUESTION_TYPES,
            true
        ) && $answer->manually_graded_at === null;
    }

    public function hasPendingManualAnswers(QuizAttempt $attempt): bool
    {
        if (
            array_key_exists(
                self::PENDING_MANUAL_ANSWERS_ATTRIBUTE,
                $attempt->getAttributes()
            )
        ) {
            return (bool) $attempt->getAttribute(self::PENDING_MANUAL_ANSWERS_ATTRIBUTE);
        }

        if (
            $attempt->relationLoaded('answers') &&
            $attempt->answers->every(
                fn (QuizAnswer $answer) => $answer->relationLoaded('questionBank')
            )
        ) {
            return $attempt->answers->contains(
                fn (QuizAnswer $answer) => $this->isPendingManualAnswer($answer)
            );
        }

        return $this->constrainPendingManualAnswers(
            QuizAnswer::query()->where('quiz_attempt_id', $attempt->getKey())
        )->exists();
    }

    public function redactPendingAttemptResults(QuizAttempt $attempt): bool
    {
        $hasPendingManualAnswers = $this->hasPendingManualAnswers($attempt);

        $attempt->makeHidden(self::PENDING_MANUAL_ANSWERS_ATTRIBUTE);

        if ($attempt->status === 'in_progress') {
            // Automatic answers are scored before submission, so keep interim
            // attempt-level results out of learner-facing responses.
            $attempt->makeHidden([
                'marks_obtained',
                'percentage',
                'result_status',
                'evaluated_at',
            ]);

            return $hasPendingManualAnswers;
        }

        if (! $hasPendingManualAnswers) {
            return false;
        }

        $attempt->makeHidden([
            'marks_obtained',
            'percentage',
            'evaluated_at',
        ]);
        $attempt->setAttribute('result_status', 'pending');

        if ($attempt->status === 'evaluated') {
            $attempt->setAttribute('status', 'submitted');
        }

        return true;
    }

    public function redactPendingGradebookResults(Gradebook $gradebook): bool
    {
        $hasPendingManualGrading = array_key_exists(
            self::PENDING_MANUAL_GRADING_ATTRIBUTE,
            $gradebook->getAttributes()
        )
            ? (bool) $gradebook->getAttribute(self::PENDING_MANUAL_GRADING_ATTRIBUTE)
            : $this->hasPendingManualGradingForCourseStudent(
                (int) $gradebook->course_id,
                (int) $gradebook->student_profile_id
            );

        $gradebook->makeHidden(self::PENDING_MANUAL_GRADING_ATTRIBUTE);

        if (! $hasPendingManualGrading) {
            return false;
        }

        $gradebook->makeHidden([
            'assignment_marks',
            'quiz_marks',
            'total_marks',
            'maximum_marks',
            'percentage',
            'grade',
        ]);
        $gradebook->setAttribute('result_status', 'pending');

        return true;
    }

    /**
     * Add a correlated pending-grading flag to certificate collections so
     * learner-facing certificate responses can be redacted without N+1
     * queries.
     */
    public function withPendingManualGradingFlagForCertificates(
        Builder|Relation $certificateQuery
    ): void {
        $pendingAttempt = QuizAttempt::query()
            ->selectRaw('1')
            ->whereColumn(
                'quiz_attempts.student_profile_id',
                'certificates.student_profile_id'
            )
            ->whereHas('quiz', function (Builder $quizQuery) {
                $quizQuery->whereColumn('quizzes.course_id', 'certificates.course_id');
            })
            ->whereHas('answers', fn (Builder $answerQuery) =>
                $this->constrainPendingManualAnswers($answerQuery)
            );

        $certificateQuery->addSelect('certificates.*');
        $certificateQuery->addSelect([
            self::PENDING_MANUAL_GRADING_ATTRIBUTE => $pendingAttempt,
        ]);
    }

    /**
     * Hide a certificate's stored result snapshot while its course still has
     * an ungraded manual answer for this student.
     */
    public function redactPendingCertificateResults(Certificate $certificate): bool
    {
        $hasPendingManualGrading = array_key_exists(
            self::PENDING_MANUAL_GRADING_ATTRIBUTE,
            $certificate->getAttributes()
        )
            ? (bool) $certificate->getAttribute(self::PENDING_MANUAL_GRADING_ATTRIBUTE)
            : $this->hasPendingManualGradingForCourseStudent(
                (int) $certificate->course_id,
                (int) $certificate->student_profile_id
            );

        $certificate->makeHidden(self::PENDING_MANUAL_GRADING_ATTRIBUTE);
        $certificate->setAttribute('grading_pending', $hasPendingManualGrading);

        if (! $hasPendingManualGrading) {
            return false;
        }

        $certificate->makeHidden([
            'final_percentage',
            'final_grade',
            'certificate_file',
        ]);

        if ($certificate->status === 'issued') {
            $certificate->setAttribute('status', 'pending');
        }

        if ($certificate->verification_status === 'valid') {
            $certificate->setAttribute('verification_status', 'pending');
        }

        if ($certificate->relationLoaded('gradebook') && $certificate->gradebook) {
            $certificate->gradebook->setAttribute(
                self::PENDING_MANUAL_GRADING_ATTRIBUTE,
                true
            );
            $this->redactPendingGradebookResults($certificate->gradebook);
        }

        return true;
    }

    /**
     * Add a correlated pending-answer flag for response collections so callers
     * can redact results without issuing one query per attempt.
     */
    public function withPendingManualAnswersFlag(Builder|Relation $attemptQuery): void
    {
        $attemptQuery->withExists([
            'answers as ' . self::PENDING_MANUAL_ANSWERS_ATTRIBUTE => fn (Builder $query) =>
                $this->constrainPendingManualAnswers($query),
        ]);
    }

    /**
     * Add one correlated flag to gradebook collections so learners can be
     * protected from stale persisted totals without issuing a query per row.
     */
    public function withPendingManualGradingFlag(Builder $gradebookQuery): void
    {
        $pendingAttempt = QuizAttempt::query()
            ->selectRaw('1')
            ->whereColumn(
                'quiz_attempts.student_profile_id',
                'gradebooks.student_profile_id'
            )
            ->whereHas('quiz', function (Builder $quizQuery) {
                $quizQuery->whereColumn('quizzes.course_id', 'gradebooks.course_id');
            })
            ->whereHas('answers', fn (Builder $answerQuery) =>
                $this->constrainPendingManualAnswers($answerQuery)
            );

        $gradebookQuery->addSelect('gradebooks.*');
        $gradebookQuery->addSelect([
            self::PENDING_MANUAL_GRADING_ATTRIBUTE => $pendingAttempt,
        ]);
    }

    public function hasPendingManualGradingForCourseStudent(
        int $courseId,
        int $studentProfileId
    ): bool {
        return QuizAttempt::query()
            ->where('student_profile_id', $studentProfileId)
            ->whereHas('quiz', function (Builder $quizQuery) use ($courseId) {
                $quizQuery->where('course_id', $courseId);
            })
            ->whereHas('answers', fn (Builder $answerQuery) =>
                $this->constrainPendingManualAnswers($answerQuery)
            )
            ->exists();
    }

    public function constrainPendingManualAnswers(Builder $query): Builder
    {
        return $query
            ->whereNull('manually_graded_at')
            ->whereHas('questionBank', function (Builder $questionQuery) {
                $questionQuery->whereIn(
                    'question_type',
                    QuestionBank::MANUAL_QUESTION_TYPES
                );
            });
    }

    public function recalculate(QuizAttempt $attempt): void
    {
        $attempt->load([
            'quiz',
            'answers.questionBank',
        ]);

        $marksObtained = $attempt->answers->sum('marks_obtained');
        $totalMarks = $attempt->total_marks > 0
            ? $attempt->total_marks
            : 1;
        $percentage = round(($marksObtained / $totalMarks) * 100, 2);

        $passingMarks = $attempt->quiz->passing_marks ?? 0;
        $passPercentage = $totalMarks > 0
            ? ($passingMarks / $totalMarks) * 100
            : 0;
        $resultStatus = $percentage >= $passPercentage
            ? 'passed'
            : 'failed';

        $requiresManualEvaluation = $attempt->answers
            ->contains(fn (QuizAnswer $answer) => in_array(
                $answer->questionBank?->question_type,
                QuestionBank::MANUAL_QUESTION_TYPES,
                true
            ));
        $pendingManualEvaluation = $attempt->answers
            ->contains(fn (QuizAnswer $answer) => $this->isPendingManualAnswer($answer));

        if ($pendingManualEvaluation) {
            $resultStatus = 'pending';
        }

        $status = $attempt->status;

        if ($pendingManualEvaluation && $status === 'evaluated') {
            $status = 'submitted';
        }

        if (
            $status === 'submitted' &&
            (! $requiresManualEvaluation || ! $pendingManualEvaluation)
        ) {
            $status = 'evaluated';
        }

        $attempt->update([
            'marks_obtained' => $marksObtained,
            'percentage' => $percentage,
            'result_status' => $resultStatus,
            'status' => $status,
            'evaluated_at' => $status === 'evaluated' ? now() : null,
        ]);
    }
}

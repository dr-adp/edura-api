<?php

namespace App\Services;

use App\Exceptions\AssessmentLockSetChanged;
use App\Models\Assignment;
use App\Models\AssignmentEvaluation;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\CourseEnrollment;
use App\Models\Gradebook;
use App\Models\Lesson;
use App\Models\LiveClass;
use App\Models\LiveClassAttendance;
use App\Models\QuestionBank;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Acquires row locks used by course-scoped academic operations.
 *
 * Canonical order (when a row in each group is needed):
 * courses by ID; course sections by ID; lessons by ID; live classes by ID;
 * student profiles by ID; enrollment pairs by (course_id, student_profile_id,
 * ID); definitions in Assignment -> QuestionBank -> Quiz -> QuizQuestion ->
 * QuestionOption order; evidence in AssignmentSubmission ->
 * AssignmentEvaluation -> QuizAttempt -> QuizAnswer -> LiveClassAttendance ->
 * Gradebook -> Certificate order.
 *
 * Every operation starts by locking all affected course rows. Discovery reads
 * may be used to form that course set, but a relationship that now points to
 * an unlocked course raises AssessmentLockSetChanged. The exception must
 * escape the transaction; retry the complete transaction after rollback.
 *
 * Course rows are the shared serialization gate. Definition mutations should
 * lock only the definition rows they change or whose relationship they need
 * to stabilize. In particular, quiz-answer mutations should not lock every
 * question-bank row in a course.
 */
class CourseAssessmentLockService
{
    /**
     * Run a complete lock-sensitive operation in one transaction, retrying a
     * changed lock set only after the transaction has rolled back. An already
     * active outer transaction remains caller-owned; changed lock sets bubble
     * to that caller so it can roll back and retry the full operation.
     */
    public function transactionWithLockSetRetries(
        callable $operation,
        int $maximumAttempts = 3
    ): mixed {
        if (DB::transactionLevel() > 0) {
            return $operation();
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($operation, attempts: 1);
            } catch (AssessmentLockSetChanged $exception) {
                if ($attempt >= max(1, $maximumAttempts)) {
                    throw $exception;
                }
            }
        }
    }

    /** Lock course rows one at a time in ascending ID order. */
    public function lockCourses(array $courseIds): void
    {
        $this->assertTransaction();

        foreach ($this->sortedIds($courseIds) as $courseId) {
            $this->lockCourse($courseId);
        }
    }

    /** Re-read and lock one course row, including soft-deleted courses. */
    public function lockCourse(int $courseId, bool $withTrashed = true): Course
    {
        $this->assertTransaction();

        $query = $withTrashed ? Course::withTrashed() : Course::query();

        return $query->whereKey($courseId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Re-read and lock one course section after its owning course row. */
    public function lockCourseSection(
        int $courseSectionId,
        bool $withTrashed = true
    ): CourseSection {
        $this->assertTransaction();

        $query = $withTrashed
            ? CourseSection::withTrashed()
            : CourseSection::query();

        return $query->whereKey($courseSectionId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Re-read and lock one lesson after its owning course row. */
    public function lockLesson(int $lessonId, bool $withTrashed = true): Lesson
    {
        $this->assertTransaction();

        $query = $withTrashed ? Lesson::withTrashed() : Lesson::query();

        return $query->whereKey($lessonId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Re-read and lock one live class after its owning course row. */
    public function lockLiveClass(
        int $liveClassId,
        bool $withTrashed = true
    ): LiveClass {
        $this->assertTransaction();

        $query = $withTrashed ? LiveClass::withTrashed() : LiveClass::query();

        return $query->whereKey($liveClassId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Re-read and lock one attendance row after its course, live class, and student. */
    public function lockLiveClassAttendance(
        int $attendanceId,
        bool $withTrashed = false
    ): LiveClassAttendance {
        $this->assertTransaction();

        $query = $withTrashed
            ? LiveClassAttendance::withTrashed()
            : LiveClassAttendance::query();

        return $query->whereKey($attendanceId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Lock an existing attendance pair, including soft-deleted rows retained
     * by the unique (live_class_id, student_profile_id) constraint.
     */
    public function lockLiveClassAttendancePair(
        int $liveClassId,
        int $studentProfileId
    ): ?LiveClassAttendance {
        $this->assertTransaction();

        return LiveClassAttendance::withTrashed()
            ->where('live_class_id', $liveClassId)
            ->where('student_profile_id', $studentProfileId)
            ->lockForUpdate()
            ->first();
    }

    /** Lock student profile rows one at a time in ascending ID order. */
    public function lockStudents(array $studentProfileIds): void
    {
        $this->assertTransaction();

        foreach ($this->sortedIds($studentProfileIds) as $studentProfileId) {
            $this->lockStudent($studentProfileId);
        }
    }

    /** Re-read and lock one student profile row. */
    public function lockStudent(int $studentProfileId): StudentProfile
    {
        $this->assertTransaction();

        return StudentProfile::query()
            ->whereKey($studentProfileId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Lock existing enrollment rows in ascending pair order. Soft-deleted
     * rows are included because their unique pair still exists in the schema;
     * callers decide whether a returned row is currently eligible.
     *
     * @param  array<int, array{course_id:int, student_profile_id:int}>  $pairs
     */
    public function lockCourseEnrollments(array $pairs): Collection
    {
        $this->assertTransaction();

        $locked = collect();

        foreach ($this->sortedPairs($pairs) as $pair) {
            $enrollment = CourseEnrollment::withTrashed()
                ->where('course_id', $pair['course_id'])
                ->where('student_profile_id', $pair['student_profile_id'])
                ->lockForUpdate()
                ->first();

            if ($enrollment) {
                $locked->push($enrollment);
            }
        }

        return $locked;
    }

    /** Lock one enrollment row by ID after its course/student parents. */
    public function lockEnrollment(int $enrollmentId): CourseEnrollment
    {
        $this->assertTransaction();

        return CourseEnrollment::withTrashed()
            ->whereKey($enrollmentId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Lock a course/student pair through its enrollment row, if one exists.
     * The returned enrollment is the locked row, including soft-deleted rows.
     */
    public function lockCourseStudentAndEnrollment(
        int $courseId,
        int $studentProfileId
    ): ?CourseEnrollment {
        $this->lockCourses([$courseId]);
        $this->lockStudents([$studentProfileId]);

        return $this->lockCourseEnrollments([[
            'course_id' => $courseId,
            'student_profile_id' => $studentProfileId,
        ]])->first();
    }

    /**
     * Lock quiz definitions and attempt evidence for a student after the
     * caller has locked the matching courses, student, and enrollments.
     */
    public function lockAssessmentEvidenceForStudent(
        array $courseIds,
        int $studentProfileId
    ): Collection {
        $this->assertTransaction();
        $courseIds = $this->sortedIds($courseIds);

        $candidateAttempts = $this->readAttemptIdentities(
            $courseIds,
            $studentProfileId
        );
        $quizzes = $this->lockQuizzes(
            $candidateAttempts->pluck('quiz_id')->map(static fn ($id): int => (int) $id)->all()
        );
        $this->assertRelationshipsStayWithinLockedCourses($quizzes, $courseIds);

        $attempts = $this->lockAttemptsForCourses($courseIds, $studentProfileId);
        $this->assertAttemptSetUnchanged($candidateAttempts, $attempts);

        return $attempts;
    }

    /**
     * Lock the definitions and evidence read by gradebook recalculation.
     *
     * The caller must already hold the course, student, and enrollment locks.
     * Assignment and quiz definitions are locked before student evidence;
     * relevant assignment submissions/evaluations, quiz attempts/answers, and
     * an existing gradebook row are then locked in canonical evidence order.
     * Question-bank rows are deliberately omitted: pending-grading detection
     * reads their question type. When definition writers use this shared
     * protocol, the course gate coordinates their changes without locking
     * shared banks for every student's calculation.
     *
     * @return array{
     *     assignment_evaluations:Collection<int, AssignmentEvaluation>,
     *     quiz_attempts:Collection<int, QuizAttempt>,
     *     gradebook:?Gradebook
     * }
     */
    public function lockGradebookCalculationInputs(
        int $courseId,
        int $studentProfileId
    ): array {
        $this->assertTransaction();

        $assignmentIdentities = Assignment::query()
            ->where('course_id', $courseId)
            ->orderBy('id')
            ->get(['id', 'course_id', 'deleted_at']);
        $assignments = $this->lockAssignments(
            $assignmentIdentities->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );
        $this->assertRowsUnchanged(
            $assignmentIdentities,
            $assignments,
            ['course_id', 'deleted_at']
        );

        foreach ($assignments as $assignment) {
            if ((int) $assignment->course_id !== $courseId) {
                throw new AssessmentLockSetChanged([$courseId, (int) $assignment->course_id]);
            }
        }

        $assignmentIds = $assignments
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $submissionIdentities = AssignmentSubmission::query()
            ->whereIn('assignment_id', $assignmentIds)
            ->where('student_profile_id', $studentProfileId)
            ->orderBy('id')
            ->get(['id', 'assignment_id', 'student_profile_id', 'deleted_at']);
        $candidateAttempts = $this->readAttemptIdentities([$courseId], $studentProfileId);
        $quizzes = $this->lockQuizzes(
            $candidateAttempts->pluck('quiz_id')->map(static fn ($id): int => (int) $id)->all()
        );
        $this->assertRelationshipsStayWithinLockedCourses($quizzes, [$courseId]);

        $submissions = $this->lockAssignmentSubmissions(
            $submissionIdentities->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );
        $this->assertRowsUnchanged(
            $submissionIdentities,
            $submissions,
            ['assignment_id', 'student_profile_id', 'deleted_at']
        );

        foreach ($submissions as $submission) {
            if (
                ! in_array((int) $submission->assignment_id, $assignmentIds, true) ||
                (int) $submission->student_profile_id !== $studentProfileId
            ) {
                throw new AssessmentLockSetChanged([$courseId], [$studentProfileId]);
            }
        }

        $submissionIds = $submissions
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $evaluations = $this->lockAssignmentEvaluationsForSubmissions($submissionIds);

        foreach ($evaluations as $evaluation) {
            if (! in_array((int) $evaluation->assignment_submission_id, $submissionIds, true)) {
                throw new AssessmentLockSetChanged([$courseId], [$studentProfileId]);
            }
        }

        $attempts = $this->lockAttemptsForCourses([$courseId], $studentProfileId);
        $this->assertAttemptSetUnchanged($candidateAttempts, $attempts);

        $attemptIds = $attempts
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $answerIdentities = QuizAnswer::query()
            ->whereIn('quiz_attempt_id', $attemptIds)
            ->orderBy('id')
            ->get(['id', 'quiz_attempt_id', 'question_bank_id', 'deleted_at']);
        $answers = $this->lockQuizAnswers(
            $answerIdentities->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );
        $this->assertRowsUnchanged(
            $answerIdentities,
            $answers,
            ['quiz_attempt_id', 'question_bank_id', 'deleted_at']
        );

        foreach ($answers as $answer) {
            if (! in_array((int) $answer->quiz_attempt_id, $attemptIds, true)) {
                throw new AssessmentLockSetChanged([$courseId], [$studentProfileId]);
            }
        }

        $questionBanks = QuestionBank::query()
            ->whereIn(
                'id',
                $answers->pluck('question_bank_id')->map(static fn ($id): int => (int) $id)->all()
            )
            ->get()
            ->keyBy('id');
        $answers->each(function (QuizAnswer $answer) use ($questionBanks): void {
            $answer->setRelation(
                'questionBank',
                $questionBanks->get($answer->question_bank_id)
            );
        });
        $answersByAttempt = $answers->groupBy('quiz_attempt_id');
        $attempts->each(function (QuizAttempt $attempt) use ($answersByAttempt): void {
            $attempt->setRelation(
                'answers',
                $answersByAttempt->get($attempt->id, collect())
            );
        });

        $gradebooks = Gradebook::withTrashed()
            ->where('course_id', $courseId)
            ->where('student_profile_id', $studentProfileId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($gradebooks as $gradebook) {
            if (
                (int) $gradebook->course_id !== $courseId ||
                (int) $gradebook->student_profile_id !== $studentProfileId
            ) {
                throw new AssessmentLockSetChanged([$courseId], [$studentProfileId]);
            }
        }

        return [
            'assignment_evaluations' => $evaluations,
            'quiz_attempts' => $attempts,
            // The pair's unique key also covers soft-deleted rows. Return the
            // locked row in either state so recalculation can safely reuse it.
            'gradebook' => $gradebooks->first(),
        ];
    }

    /**
     * Lock active attempts for the supplied courses in ascending ID order.
     * Call after course, student, enrollment, and any needed definition locks.
     */
    public function lockAttemptsForCourses(
        array $courseIds,
        ?int $studentProfileId = null
    ): Collection {
        $this->assertTransaction();

        $courseIds = $this->sortedIds($courseIds);

        if ($courseIds === []) {
            return collect();
        }

        return QuizAttempt::query()
            ->whereHas('quiz', fn ($query) => $query->whereIn('course_id', $courseIds))
            ->when(
                $studentProfileId !== null,
                fn ($query) => $query->where(
                    'student_profile_id',
                    $studentProfileId
                )
            )
            ->orderBy('quiz_attempts.id')
            ->lockForUpdate()
            ->get([
                'quiz_attempts.id',
                'quiz_attempts.quiz_id',
                'quiz_attempts.student_profile_id',
                'quiz_attempts.status',
                'quiz_attempts.marks_obtained',
                'quiz_attempts.total_marks',
            ])
            ->load('quiz:id,course_id');
    }

    /** Lock a quiz's attempts in ascending ID order after their parent locks. */
    public function lockAttemptsForQuiz(int $quizId): Collection
    {
        $this->assertTransaction();

        return QuizAttempt::query()
            ->where('quiz_id', $quizId)
            ->orderBy('quiz_attempts.id')
            ->lockForUpdate()
            ->get(['quiz_attempts.id', 'quiz_attempts.quiz_id', 'quiz_attempts.student_profile_id'])
            ->load('quiz:id,course_id');
    }

    /** Lock a student's attempts in ascending ID order after parent locks. */
    public function lockAttemptsForStudent(int $studentProfileId): Collection
    {
        $this->assertTransaction();

        return QuizAttempt::query()
            ->whereHas('quiz')
            ->where('student_profile_id', $studentProfileId)
            ->orderBy('quiz_attempts.id')
            ->lockForUpdate()
            ->get(['quiz_attempts.id', 'quiz_attempts.quiz_id', 'quiz_attempts.student_profile_id'])
            ->load('quiz:id,course_id');
    }

    /**
     * Lock course, student, enrollment, quiz-definition, then attempt rows.
     * When no student is supplied, all current attempt identities are
     * discovered after the course gates are held and rechecked after locks.
     */
    public function lockCoursesStudentsAndAttempts(
        array $courseIds,
        ?int $studentProfileId = null
    ): Collection {
        $courseIds = $this->sortedIds($courseIds);

        if ($courseIds === []) {
            return collect();
        }

        $this->lockCourses($courseIds);

        if ($studentProfileId !== null) {
            $this->lockStudents([$studentProfileId]);
            $this->lockCourseEnrollments(array_map(
                fn (int $courseId): array => [
                    'course_id' => $courseId,
                    'student_profile_id' => $studentProfileId,
                ],
                $courseIds
            ));

            return $this->lockAssessmentEvidenceForStudent(
                $courseIds,
                $studentProfileId
            );
        }

        $candidateAttempts = $this->readAttemptIdentities(
            $courseIds,
            null
        );

        $studentIds = $candidateAttempts
            ->pluck('student_profile_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $this->lockStudents($studentIds);

        $enrollmentPairs = $candidateAttempts
            ->map(fn (QuizAttempt $attempt): array => [
                'course_id' => (int) $attempt->quiz->course_id,
                'student_profile_id' => (int) $attempt->student_profile_id,
            ])
            ->all();

        $this->lockCourseEnrollments($enrollmentPairs);

        $quizIds = $candidateAttempts
            ->pluck('quiz_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $lockedQuizzes = $this->lockQuizzes($quizIds);

        $this->assertRelationshipsStayWithinLockedCourses(
            $lockedQuizzes,
            $courseIds
        );

        $attempts = $this->lockAttemptsForCourses(
            $courseIds,
            null
        );

        $this->assertAttemptSetUnchanged($candidateAttempts, $attempts);

        return $attempts;
    }

    /**
     * Lock the course gate and re-read a quiz for an attempt-creation caller.
     * This helper intentionally does not lock the quiz row: existing callers
     * lock the target student immediately after this call, and the canonical
     * order requires student/enrollment rows before definitions. The course
     * row serializes same-course attempt allocation once definition writers
     * use this service. Pass a student ID in a future caller when quiz-row
     * locking is also needed within this helper.
     */
    public function lockQuizAndCourses(
        int $quizId,
        array $additionalCourseIds = [],
        ?int $studentProfileId = null
    ): Quiz {
        $this->assertTransaction();

        $identity = Quiz::withTrashed()
            ->whereKey($quizId)
            ->firstOrFail();
        $courseIds = $this->sortedIds(array_merge(
            [(int) $identity->course_id],
            $additionalCourseIds
        ));

        $this->lockCourses($courseIds);

        if ($studentProfileId !== null) {
            $this->lockStudents([$studentProfileId]);
            $this->lockCourseEnrollments(array_map(
                fn (int $courseId): array => [
                    'course_id' => $courseId,
                    'student_profile_id' => $studentProfileId,
                ],
                $courseIds
            ));
        }

        $quiz = $studentProfileId === null
            ? Quiz::withTrashed()->whereKey($quizId)->firstOrFail()
            : $this->lockQuizDefinitions([$quizId])->first();

        if (! $quiz || ! in_array((int) $quiz->course_id, $courseIds, true)) {
            throw new AssessmentLockSetChanged($courseIds);
        }

        return $quiz->load('course');
    }

    /**
     * Lock a quiz definition and every course gate that its course move may
     * affect. Definition writers use this after collecting the route-bound
     * and requested course IDs; attempts continue to use the student-aware
     * helpers above.
     */
    public function lockQuizDefinitionAndCourses(
        int $quizId,
        array $additionalCourseIds = []
    ): Quiz {
        $this->assertTransaction();

        $identity = Quiz::withTrashed()->whereKey($quizId)->firstOrFail();
        $initialLinks = $this->readQuizQuestionIdentitiesForQuiz($quizId);
        $initialQuestionBankIds = $initialLinks
            ->pluck('question_bank_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $courseIds = $this->sortedIds(array_merge(
            [(int) $identity->course_id],
            $this->readDefinitionCourseIds([], $initialQuestionBankIds),
            $additionalCourseIds
        ));

        $this->lockCourses($courseIds);
        $currentQuiz = Quiz::withTrashed()->whereKey($quizId)->firstOrFail();
        $currentLinks = $this->readQuizQuestionIdentitiesForQuiz($quizId);
        $questionBankIds = $currentLinks
            ->pluck('question_bank_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $requiredCourseIds = $this->sortedIds(array_merge(
            [(int) $currentQuiz->course_id],
            $this->readDefinitionCourseIds([], $questionBankIds),
            $currentLinks->pluck('course_id')->map(static fn ($id): int => (int) $id)->all()
        ));
        $this->assertCourseSetIsLocked($requiredCourseIds, $courseIds);

        $questionBanks = $questionBankIds === []
            ? collect()
            : $this->lockQuestionBanks($questionBankIds);
        $this->assertCourseSetIsLocked(
            $questionBanks->pluck('course_id')->map(static fn ($id): int => (int) $id)->all(),
            $courseIds
        );
        $quiz = $this->lockQuizDefinitions([$quizId])->first();

        if (! $quiz || ! in_array((int) $quiz->course_id, $courseIds, true)) {
            throw new AssessmentLockSetChanged(array_merge(
                $courseIds,
                [(int) ($quiz?->course_id ?? 0)]
            ));
        }

        $quizQuestionIds = $currentLinks->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $quizQuestions = $quizQuestionIds === []
            ? collect()
            : $this->lockQuizQuestions($quizQuestionIds);
        $currentLinkIdentities = $currentLinks
            ->map(static fn ($link): string => (int) $link->id . ':' . (int) $link->quiz_id . ':' . (int) $link->question_bank_id)
            ->sort()
            ->values()
            ->all();
        $lockedLinkIdentities = $quizQuestions
            ->map(static fn (QuizQuestion $link): string => (int) $link->id . ':' . (int) $link->quiz_id . ':' . (int) $link->question_bank_id)
            ->sort()
            ->values()
            ->all();
        if ($currentLinkIdentities !== $lockedLinkIdentities) {
            throw new AssessmentLockSetChanged($courseIds);
        }

        $quiz->setRelation(
            'lockedDefinitionQuestionLinks',
            $quizQuestions->each(function (QuizQuestion $link) use ($questionBanks): void {
                $link->setRelation(
                    'questionBank',
                    $questionBanks->firstWhere('id', (int) $link->question_bank_id)
                );
            })
        );

        return $quiz->load('course');
    }

    /**
     * Lock the full parent chain and existing attempt evidence before a new
     * attempt is allocated. The course row serializes same-course attempt
     * creation; the unique index remains the final attempt-number guard.
     *
     * @return array{
     *     quiz:Quiz,
     *     course:Course,
     *     student:StudentProfile,
     *     enrollment:?CourseEnrollment,
     *     attempts:Collection<int, QuizAttempt>
     * }
     */
    public function lockQuizForAttemptCreation(
        int $quizId,
        int $studentProfileId
    ): array {
        $this->assertTransaction();

        $identity = Quiz::withTrashed()
            ->whereKey($quizId)
            ->firstOrFail();
        $initialCourseId = (int) $identity->course_id;

        $course = $this->lockCourse($initialCourseId);
        $student = $this->lockStudent($studentProfileId);
        $enrollment = $this->lockCourseEnrollments([[
            'course_id' => $initialCourseId,
            'student_profile_id' => $studentProfileId,
        ]])->first();
        $quiz = $this->lockQuizDefinitions([$quizId])->first();

        if (! $quiz || (int) $quiz->course_id !== $initialCourseId) {
            throw new AssessmentLockSetChanged([
                $initialCourseId,
                (int) ($quiz?->course_id ?? 0),
            ]);
        }

        $quiz->setRelation('course', $course);
        $attempts = $this->lockAttemptsForQuizAndStudent($quizId, $studentProfileId);

        return compact('quiz', 'course', 'student', 'enrollment', 'attempts');
    }

    /**
     * Lock the course/student/enrollment parents before a quiz attempt and its
     * answers. Question-bank rows are deliberately omitted: the course gate
     * serializes definition changes without locking shared question banks for
     * every learner answer mutation.
     */
    public function lockAttemptForMutation(
        int $attemptId,
        ?int $questionBankId = null,
        ?int $questionOptionId = null
    ): QuizAttempt
    {
        $this->assertTransaction();

        $identity = QuizAttempt::query()
            ->with('quiz:id,course_id')
            ->findOrFail($attemptId);
        $quizId = (int) $identity->quiz_id;
        $studentProfileId = (int) $identity->student_profile_id;
        $initialCourseId = (int) ($identity->quiz?->course_id ?? 0);

        if ($initialCourseId < 1) {
            throw new AssessmentLockSetChanged([]);
        }

        $course = $this->lockCourse($initialCourseId);
        $student = $this->lockStudent($studentProfileId);
        $enrollment = $this->lockCourseEnrollments([[
            'course_id' => $initialCourseId,
            'student_profile_id' => $studentProfileId,
        ]])->first();

        $quiz = $this->lockQuizDefinitions([$quizId])->first();

        if (! $quiz || (int) $quiz->course_id !== $initialCourseId) {
            throw new AssessmentLockSetChanged([$initialCourseId]);
        }

        $quizQuestion = null;
        if ($questionBankId !== null) {
            $quizQuestion = $this->lockQuizQuestionForPair($quizId, $questionBankId);
        }

        $questionOption = null;
        if ($questionOptionId !== null) {
            $questionOption = $this->lockQuestionOptionForBank(
                $questionOptionId,
                $questionBankId
            );
        }

        $attempt = QuizAttempt::query()
            ->whereKey($attemptId)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            (int) $attempt->quiz_id !== $quizId ||
            (int) $attempt->student_profile_id !== $studentProfileId
        ) {
            throw new AssessmentLockSetChanged([$initialCourseId]);
        }

        $quiz->setRelation('course', $course);
        $attempt->setRelation('quiz', $quiz);
        $attempt->setRelation('studentProfile', $student);
        $attempt->setRelation('courseEnrollment', $enrollment);
        $attempt->setRelation('lockedQuizQuestion', $quizQuestion);
        $attempt->setRelation('lockedQuestionOption', $questionOption);
        $attempt->makeHidden('courseEnrollment');
        $attempt->makeHidden(['lockedQuizQuestion', 'lockedQuestionOption']);

        return $attempt;
    }

    /**
     * Lock all answer evidence for one attempt after its parent attempt row.
     * Question-bank rows remain unlocked because they are shared definitions.
     *
     * @return Collection<int, QuizAnswer>
     */
    public function lockQuizAnswersForAttempt(int $attemptId): Collection
    {
        $this->assertTransaction();

        return QuizAnswer::query()
            ->where('quiz_attempt_id', $attemptId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->load('questionBank');
    }

    /** Lock a specific answer after its parent attempt, and verify ownership. */
    public function lockQuizAnswerForAttempt(
        int $answerId,
        int $attemptId
    ): QuizAnswer {
        $this->assertTransaction();

        return QuizAnswer::query()
            ->whereKey($answerId)
            ->where('quiz_attempt_id', $attemptId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Lock an existing answer for an attempt/question pair after the attempt. */
    public function lockQuizAnswerForQuestion(
        int $attemptId,
        int $questionBankId
    ): ?QuizAnswer {
        $this->assertTransaction();

        return QuizAnswer::withTrashed()
            ->where('quiz_attempt_id', $attemptId)
            ->where('question_bank_id', $questionBankId)
            ->lockForUpdate()
            ->first();
    }

    /** Lock one quiz/question link after the quiz definition row. */
    public function lockQuizQuestionForPair(
        int $quizId,
        int $questionBankId
    ): ?QuizQuestion {
        $this->assertTransaction();

        return QuizQuestion::query()
            ->where('quiz_id', $quizId)
            ->where('question_bank_id', $questionBankId)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
    }

    /** Lock an option after its quiz/question link; shared banks stay unlocked. */
    public function lockQuestionOptionForBank(
        int $questionOptionId,
        ?int $questionBankId
    ): ?QuestionOption {
        $this->assertTransaction();

        return QuestionOption::query()
            ->whereKey($questionOptionId)
            ->when(
                $questionBankId !== null,
                fn ($query) => $query->where('question_bank_id', $questionBankId)
            )
            ->lockForUpdate()
            ->first();
    }

    /**
     * Lock attempt-number history for one quiz/student pair, including soft
     * deleted rows because the unique index still reserves those numbers.
     * Call after the parent rows and quiz definition have been locked.
     *
     * @return Collection<int, QuizAttempt>
     */
    public function lockAttemptsForQuizAndStudent(
        int $quizId,
        int $studentProfileId
    ): Collection {
        $this->assertTransaction();

        return QuizAttempt::withTrashed()
            ->where('quiz_id', $quizId)
            ->where('student_profile_id', $studentProfileId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get([
                'id',
                'quiz_id',
                'student_profile_id',
                'attempt_number',
                'status',
                'deleted_at',
            ]);
    }

    /**
     * Lock all course gates affected by one or more shared question banks,
     * followed by banks, linked quizzes, and quiz-question links in canonical
     * definition order. Discovery is repeated after the course gates are
     * held; a newly required course escapes as AssessmentLockSetChanged so
     * the complete transaction can retry after rollback.
     *
     * @return array{
     *     question_banks:Collection<int, QuestionBank>,
     *     quizzes:Collection<int, Quiz>,
     *     quiz_questions:Collection<int, QuizQuestion>,
     *     course_ids:list<int>
     * }
     */
    public function lockQuestionBanksAndCourses(
        array $questionBankIds,
        array $additionalCourseIds = []
    ): array {
        $this->assertTransaction();

        $questionBankIds = $this->sortedIds($questionBankIds);
        foreach ($questionBankIds as $questionBankId) {
            QuestionBank::query()->whereKey($questionBankId)->firstOrFail();
        }

        $initialBanks = QuestionBank::query()
            ->whereIn('id', $questionBankIds)
            ->orderBy('id')
            ->get(['id', 'course_id']);
        $initialLinks = $this->readQuizQuestionIdentitiesForQuestionBanks($questionBankIds);
        $courseIds = $this->sortedIds(array_merge(
            $initialBanks->pluck('course_id')->map(static fn ($id): int => (int) $id)->all(),
            $initialLinks->pluck('course_id')->map(static fn ($id): int => (int) $id)->all(),
            $additionalCourseIds
        ));

        $this->lockCourses($courseIds);

        $currentBanks = QuestionBank::query()
            ->whereIn('id', $questionBankIds)
            ->orderBy('id')
            ->get(['id', 'course_id']);
        if ($currentBanks->count() !== count($questionBankIds)) {
            throw new AssessmentLockSetChanged($courseIds);
        }

        $currentLinks = $this->readQuizQuestionIdentitiesForQuestionBanks($questionBankIds);
        $requiredCourseIds = $this->sortedIds(array_merge(
            $currentBanks->pluck('course_id')->map(static fn ($id): int => (int) $id)->all(),
            $currentLinks->pluck('course_id')->map(static fn ($id): int => (int) $id)->all()
        ));
        $this->assertCourseSetIsLocked($requiredCourseIds, $courseIds);

        $questionBanks = $questionBankIds === []
            ? collect()
            : $this->lockQuestionBanks($questionBankIds);
        $this->assertCourseSetIsLocked(
            $questionBanks->pluck('course_id')->map(static fn ($id): int => (int) $id)->all(),
            $courseIds
        );

        $quizIds = $currentLinks->pluck('quiz_id')->map(static fn ($id): int => (int) $id)->unique()->all();
        $quizzes = $quizIds === []
            ? collect()
            : $this->lockQuizDefinitions($quizIds);
        $this->assertRelationshipsStayWithinLockedCourses($quizzes, $courseIds);

        $quizQuestionIds = $currentLinks
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $quizQuestions = $quizQuestionIds === []
            ? collect()
            : $this->lockQuizQuestions($quizQuestionIds);
        $currentLinkIds = $currentLinks
            ->map(static fn ($link): string => (int) $link->id . ':' . (int) $link->quiz_id . ':' . (int) $link->question_bank_id)
            ->sort()
            ->values()
            ->all();
        $lockedLinkIds = $quizQuestions
            ->map(static fn (QuizQuestion $link): string => (int) $link->id . ':' . (int) $link->quiz_id . ':' . (int) $link->question_bank_id)
            ->sort()
            ->values()
            ->all();
        if ($currentLinkIds !== $lockedLinkIds) {
            throw new AssessmentLockSetChanged($courseIds);
        }

        return [
            'question_banks' => $questionBanks,
            'quizzes' => $quizzes,
            'quiz_questions' => $quizQuestions,
            'course_ids' => $courseIds,
        ];
    }

    /** Lock one bank through the shared-bank-aware multi-bank protocol. */
    public function lockQuestionBankAndCourses(
        int $questionBankId,
        array $additionalCourseIds = []
    ): QuestionBank {
        $locked = $this->lockQuestionBanksAndCourses(
            [$questionBankId],
            $additionalCourseIds
        );

        return $locked['question_banks']->first()->load('course');
    }

    /**
     * Lock parents and optionally one existing quiz-question link for a
     * create, reassignment, update, or deletion. The caller supplies all known
     * old and new parent IDs; a changed link identity is rediscovered before
     * any definition row is locked and causes a full retry when needed.
     *
     * @return array{
     *     quiz_question:?QuizQuestion,
     *     quizzes:Collection<int, Quiz>,
     *     question_banks:Collection<int, QuestionBank>,
     *     course_ids:list<int>
     * }
     */
    public function lockQuizQuestionDefinitionAndCourses(
        ?int $quizQuestionId,
        array $quizIds,
        array $questionBankIds,
        array $additionalCourseIds = []
    ): array {
        $this->assertTransaction();

        $initialLink = $quizQuestionId === null
            ? null
            : QuizQuestion::query()->whereKey($quizQuestionId)->firstOrFail();
        $quizIds = $this->sortedIds(array_merge(
            $quizIds,
            $initialLink ? [(int) $initialLink->quiz_id] : []
        ));
        $questionBankIds = $this->sortedIds(array_merge(
            $questionBankIds,
            $initialLink ? [(int) $initialLink->question_bank_id] : []
        ));

        foreach ($quizIds as $quizId) {
            Quiz::withTrashed()->whereKey($quizId)->firstOrFail();
        }
        foreach ($questionBankIds as $questionBankId) {
            QuestionBank::query()->whereKey($questionBankId)->firstOrFail();
        }

        $courseIds = $this->readDefinitionCourseIds($quizIds, $questionBankIds, $additionalCourseIds);
        $this->lockCourses($courseIds);

        $currentLink = $quizQuestionId === null
            ? null
            : QuizQuestion::query()->whereKey($quizQuestionId)->firstOrFail();
        $currentQuizIds = $this->sortedIds(array_merge(
            $quizIds,
            $currentLink ? [(int) $currentLink->quiz_id] : []
        ));
        $currentBankIds = $this->sortedIds(array_merge(
            $questionBankIds,
            $currentLink ? [(int) $currentLink->question_bank_id] : []
        ));
        $requiredCourseIds = $this->readDefinitionCourseIds(
            $currentQuizIds,
            $currentBankIds,
            $additionalCourseIds
        );
        $this->assertCourseSetIsLocked($requiredCourseIds, $courseIds);

        $questionBanks = $currentBankIds === []
            ? collect()
            : $this->lockQuestionBanks($currentBankIds);
        $quizzes = $currentQuizIds === []
            ? collect()
            : $this->lockQuizDefinitions($currentQuizIds);
        $this->assertCourseSetIsLocked(
            $questionBanks->pluck('course_id')->map(static fn ($id): int => (int) $id)->all(),
            $courseIds
        );
        $this->assertRelationshipsStayWithinLockedCourses($quizzes, $courseIds);

        if ($quizQuestionId !== null) {
            $latestLink = QuizQuestion::query()->whereKey($quizQuestionId)->firstOrFail();
            if (
                ! in_array((int) $latestLink->quiz_id, $currentQuizIds, true) ||
                ! in_array((int) $latestLink->question_bank_id, $currentBankIds, true)
            ) {
                throw new AssessmentLockSetChanged($courseIds);
            }

            $quizQuestion = $this->lockQuizQuestions([$quizQuestionId])->first();
            if (
                ! $quizQuestion ||
                (int) $quizQuestion->quiz_id !== (int) $latestLink->quiz_id ||
                (int) $quizQuestion->question_bank_id !== (int) $latestLink->question_bank_id
            ) {
                throw new AssessmentLockSetChanged($courseIds);
            }
        } else {
            $quizQuestion = null;
        }

        return [
            'quiz_question' => $quizQuestion,
            'quizzes' => $quizzes,
            'question_banks' => $questionBanks,
            'course_ids' => $courseIds,
        ];
    }

    /** Lock one option after its bank, linked quizzes, and links. */
    public function lockQuestionOptionAndCourses(
        int $questionOptionId,
        array $additionalQuestionBankIds = []
    ): array {
        $this->assertTransaction();

        $initialOption = QuestionOption::query()->whereKey($questionOptionId)->firstOrFail();
        $questionBankIds = $this->sortedIds(array_merge(
            [(int) $initialOption->question_bank_id],
            $additionalQuestionBankIds
        ));
        $parents = $this->lockQuestionBanksAndCourses($questionBankIds);

        $currentOption = QuestionOption::query()->whereKey($questionOptionId)->firstOrFail();
        if (! in_array((int) $currentOption->question_bank_id, $questionBankIds, true)) {
            $requiredCourseIds = $this->discoverQuestionBankCourseIds([
                (int) $currentOption->question_bank_id,
            ]);
            throw new AssessmentLockSetChanged(array_merge(
                $parents['course_ids'],
                $requiredCourseIds
            ));
        }

        $option = $this->lockQuestionOptions([$questionOptionId])->first();
        if (! $option || ! in_array((int) $option->question_bank_id, $questionBankIds, true)) {
            throw new AssessmentLockSetChanged($parents['course_ids']);
        }

        return [
            'question_option' => $option->load('questionBank.course'),
            'question_banks' => $parents['question_banks'],
            'quizzes' => $parents['quizzes'],
            'quiz_questions' => $parents['quiz_questions'],
            'course_ids' => $parents['course_ids'],
        ];
    }

    /** Lock the exact parent rows and quiz-question link in canonical order. */
    public function lockQuizQuestionAndCourses(int $quizQuestionId): QuizQuestion
    {
        $this->assertTransaction();

        $identity = QuizQuestion::query()->findOrFail($quizQuestionId);
        $questionBank = QuestionBank::query()->findOrFail($identity->question_bank_id);
        $quiz = Quiz::withTrashed()->findOrFail($identity->quiz_id);
        $courseIds = $this->sortedIds([
            (int) $questionBank->course_id,
            (int) $quiz->course_id,
        ]);

        $this->lockCourses($courseIds);

        $lockedQuestionBank = $this->lockQuestionBanks([(int) $identity->question_bank_id])->first();
        $lockedQuiz = $this->lockQuizzes([(int) $identity->quiz_id])->first();

        if (
            ! $lockedQuestionBank ||
            ! $lockedQuiz ||
            ! in_array((int) $lockedQuestionBank->course_id, $courseIds, true) ||
            ! in_array((int) $lockedQuiz->course_id, $courseIds, true)
        ) {
            throw new AssessmentLockSetChanged($courseIds);
        }

        $quizQuestion = $this->lockQuizQuestions([$quizQuestionId])->first();

        if (
            ! $quizQuestion ||
            (int) $quizQuestion->question_bank_id !== (int) $lockedQuestionBank->id ||
            (int) $quizQuestion->quiz_id !== (int) $lockedQuiz->id
        ) {
            throw new AssessmentLockSetChanged($courseIds);
        }

        return $quizQuestion->load([
            'quiz.course',
            'questionBank.course',
        ]);
    }

    /** Lock QBank before Quiz after locking both affected course gates. */
    public function lockQuizAndQuestionBankAndCourses(
        int $quizId,
        int $questionBankId
    ): array {
        $this->assertTransaction();

        $questionBankIdentity = QuestionBank::query()->findOrFail($questionBankId);
        $quizIdentity = Quiz::withTrashed()->findOrFail($quizId);
        $courseIds = $this->sortedIds([
            (int) $questionBankIdentity->course_id,
            (int) $quizIdentity->course_id,
        ]);

        $this->lockCourses($courseIds);

        $questionBank = $this->lockQuestionBanks([$questionBankId])->first();
        $quiz = $this->lockQuizzes([$quizId])->first();

        if (
            ! $questionBank ||
            ! $quiz ||
            ! in_array((int) $questionBank->course_id, $courseIds, true) ||
            ! in_array((int) $quiz->course_id, $courseIds, true)
        ) {
            throw new AssessmentLockSetChanged($courseIds);
        }

        return [
            $quiz->load('course'),
            $questionBank->load('course'),
        ];
    }

    /** Lock a student's course, profile, enrollments, quizzes, then attempts. */
    public function lockStudentProfileForMutation(int $studentProfileId): StudentProfile
    {
        $this->assertTransaction();

        $candidateAttempts = QuizAttempt::query()
            ->whereHas('quiz')
            ->where('student_profile_id', $studentProfileId)
            ->with('quiz:id,course_id')
            ->orderBy('quiz_attempts.id')
            ->get(['quiz_attempts.id', 'quiz_attempts.quiz_id', 'quiz_attempts.student_profile_id']);
        $courseIds = $this->sortedIds(
            $candidateAttempts
                ->map(fn (QuizAttempt $attempt) => $attempt->quiz?->course_id)
                ->filter()
                ->map(static fn ($id): int => (int) $id)
                ->all()
        );

        $this->lockCourses($courseIds);
        $this->lockStudents([$studentProfileId]);

        $pairs = $candidateAttempts
            ->filter(fn (QuizAttempt $attempt) => $attempt->quiz)
            ->map(fn (QuizAttempt $attempt): array => [
                'course_id' => (int) $attempt->quiz->course_id,
                'student_profile_id' => $studentProfileId,
            ])
            ->all();
        $this->lockCourseEnrollments($pairs);

        $lockedQuizzes = $this->lockQuizzes(
            $candidateAttempts->pluck('quiz_id')->map(static fn ($id): int => (int) $id)->all()
        );
        $this->assertRelationshipsStayWithinLockedCourses($lockedQuizzes, $courseIds);

        $attempts = $this->lockAttemptsForStudent($studentProfileId);
        $this->assertAttemptSetUnchanged($candidateAttempts, $attempts);

        return StudentProfile::query()->whereKey($studentProfileId)->firstOrFail();
    }

    /** Lock a table's rows by ascending primary key after its parent locks. */
    public function lockAssignments(array $ids): Collection
    {
        return $this->lockRows(Assignment::class, $ids, true);
    }

    public function lockQuestionBanks(array $ids): Collection
    {
        return $this->lockRows(QuestionBank::class, $ids);
    }

    public function lockQuizQuestions(array $ids): Collection
    {
        return $this->lockRows(QuizQuestion::class, $ids);
    }

    public function lockQuestionOptions(array $ids): Collection
    {
        return $this->lockRows(QuestionOption::class, $ids);
    }

    public function lockAssignmentSubmissions(array $ids): Collection
    {
        return $this->lockRows(AssignmentSubmission::class, $ids, true);
    }

    /**
     * Lock the existing submission for an assignment/student unique pair,
     * including soft-deleted history. Call after course, student, enrollment,
     * and assignment locks. The composite unique index also protects the
     * no-row case on databases that use next-key/gap locks.
     */
    public function lockAssignmentSubmissionForPair(
        int $assignmentId,
        int $studentProfileId
    ): ?AssignmentSubmission {
        $this->assertTransaction();

        return AssignmentSubmission::withTrashed()
            ->where('assignment_id', $assignmentId)
            ->where('student_profile_id', $studentProfileId)
            ->lockForUpdate()
            ->first();
    }

    public function lockAssignmentEvaluations(array $ids): Collection
    {
        return $this->lockRows(AssignmentEvaluation::class, $ids, true);
    }

    /**
     * Lock evaluations belonging to the supplied submissions, plus known
     * evaluation IDs, in ascending evaluation ID order. Call after course,
     * student, assignment, and submission locks.
     *
     * @param  list<int>  $submissionIds
     * @param  list<int>  $evaluationIds
     */
    public function lockAssignmentEvaluationsForSubmissions(
        array $submissionIds,
        array $evaluationIds = []
    ): Collection {
        $this->assertTransaction();
        $submissionIds = $this->sortedIds($submissionIds);
        $evaluationIds = $this->sortedIds($evaluationIds);

        if ($submissionIds === [] && $evaluationIds === []) {
            return collect();
        }

        return AssignmentEvaluation::withTrashed()
            ->where(function ($query) use ($submissionIds, $evaluationIds): void {
                if ($evaluationIds !== []) {
                    $query->whereIn('id', $evaluationIds);
                }

                if ($submissionIds !== []) {
                    $method = $evaluationIds === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('assignment_submission_id', $submissionIds);
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function lockQuizAnswers(array $ids): Collection
    {
        return $this->lockRows(QuizAnswer::class, $ids, true);
    }

    public function lockGradebooks(array $ids): Collection
    {
        return $this->lockRows(Gradebook::class, $ids, true);
    }

    public function lockCertificates(array $ids): Collection
    {
        return $this->lockRows(Certificate::class, $ids, true);
    }

    /**
     * Quiz rows are definitions. Call only after course, student, and
     * enrollment locks when those rows are part of the operation.
     *
     * @return Collection<int, Quiz>
     */
    public function lockQuizDefinitions(array $quizIds): Collection
    {
        return $this->lockQuizzes($quizIds);
    }

    /** @return Collection<int, Quiz> */
    private function lockQuizzes(array $quizIds): Collection
    {
        $quizzes = collect();

        foreach ($this->sortedIds($quizIds) as $quizId) {
            $quizzes->push(
                Quiz::withTrashed()
                    ->whereKey($quizId)
                    ->lockForUpdate()
                    ->firstOrFail()
            );
        }

        return $quizzes;
    }

    /** @return Collection<int, Model> */
    private function lockRows(string $modelClass, array $ids, bool $withTrashed = false): Collection
    {
        $this->assertTransaction();
        $rows = collect();

        foreach ($this->sortedIds($ids) as $id) {
            $query = $withTrashed
                ? $modelClass::withTrashed()
                : $modelClass::query();

            $rows->push(
                $query->whereKey($id)
                    ->lockForUpdate()
                    ->firstOrFail()
            );
        }

        return $rows;
    }

    /** Read identities without locks; callers lock parents before locking rows. */
    private function readAttemptIdentities(array $courseIds, ?int $studentProfileId): Collection
    {
        return QuizAttempt::query()
            ->whereHas('quiz', fn ($query) => $query->whereIn('course_id', $courseIds))
            ->when(
                $studentProfileId !== null,
                fn ($query) => $query->where('student_profile_id', $studentProfileId)
            )
            ->with('quiz:id,course_id')
            ->orderBy('quiz_attempts.id')
            ->get([
                'quiz_attempts.id',
                'quiz_attempts.quiz_id',
                'quiz_attempts.student_profile_id',
            ]);
    }

    private function readLinkedQuizIdentities(int $questionBankId): Collection
    {
        return QuizQuestion::query()
            ->where('question_bank_id', $questionBankId)
            ->with('quiz:id,course_id')
            ->orderBy('quiz_id')
            ->get(['id', 'quiz_id', 'question_bank_id'])
            ->map(fn (QuizQuestion $link): ?Quiz => $link->quiz)
            ->filter()
            ->unique('id')
            ->values();
    }

    /** Read all quiz-question and quiz-course identities for shared banks. */
    private function readQuizQuestionIdentitiesForQuestionBanks(array $questionBankIds): Collection
    {
        $questionBankIds = $this->sortedIds($questionBankIds);
        if ($questionBankIds === []) {
            return collect();
        }

        return DB::table('quiz_questions')
            ->join('quizzes', 'quizzes.id', '=', 'quiz_questions.quiz_id')
            ->whereIn('quiz_questions.question_bank_id', $questionBankIds)
            ->orderBy('quiz_questions.id')
            ->get([
                'quiz_questions.id',
                'quiz_questions.quiz_id',
                'quiz_questions.question_bank_id',
                'quizzes.course_id',
            ]);
    }

    /** Read one quiz's linked bank and bank-course identities without locks. */
    private function readQuizQuestionIdentitiesForQuiz(int $quizId): Collection
    {
        return DB::table('quiz_questions')
            ->join('question_banks', 'question_banks.id', '=', 'quiz_questions.question_bank_id')
            ->where('quiz_questions.quiz_id', $quizId)
            ->orderBy('quiz_questions.id')
            ->get([
                'quiz_questions.id',
                'quiz_questions.quiz_id',
                'quiz_questions.question_bank_id',
                'question_banks.course_id',
            ]);
    }

    /** Discover the full parent-course set before acquiring definition locks. */
    private function readDefinitionCourseIds(
        array $quizIds,
        array $questionBankIds,
        array $additionalCourseIds = []
    ): array {
        $quizIds = $this->sortedIds($quizIds);
        $questionBankIds = $this->sortedIds($questionBankIds);

        $quizCourses = $quizIds === []
            ? collect()
            : Quiz::withTrashed()->whereIn('id', $quizIds)->get(['id', 'course_id']);
        $questionBankCourses = $questionBankIds === []
            ? collect()
            : QuestionBank::query()->whereIn('id', $questionBankIds)->get(['id', 'course_id']);

        if (
            $quizCourses->count() !== count($quizIds) ||
            $questionBankCourses->count() !== count($questionBankIds)
        ) {
            throw new AssessmentLockSetChanged($additionalCourseIds);
        }

        return $this->sortedIds(array_merge(
            $quizCourses->pluck('course_id')->map(static fn ($id): int => (int) $id)->all(),
            $questionBankCourses->pluck('course_id')->map(static fn ($id): int => (int) $id)->all(),
            $additionalCourseIds
        ));
    }

    /** Discover the course gates required by a bank and all of its links. */
    private function discoverQuestionBankCourseIds(array $questionBankIds): array
    {
        $questionBankIds = $this->sortedIds($questionBankIds);
        $banks = QuestionBank::query()
            ->whereIn('id', $questionBankIds)
            ->get(['id', 'course_id']);
        $links = $this->readQuizQuestionIdentitiesForQuestionBanks($questionBankIds);

        return $this->sortedIds(array_merge(
            $banks->pluck('course_id')->map(static fn ($id): int => (int) $id)->all(),
            $links->pluck('course_id')->map(static fn ($id): int => (int) $id)->all()
        ));
    }

    private function assertRelationshipsStayWithinLockedCourses(
        Collection $quizzes,
        array $lockedCourseIds
    ): void {
        foreach ($quizzes as $quiz) {
            $this->assertCourseIsLocked((int) $quiz->course_id, $lockedCourseIds);
        }
    }

    private function assertCourseIsLocked(int $courseId, array $lockedCourseIds): void
    {
        if (! in_array($courseId, $lockedCourseIds, true)) {
            throw new AssessmentLockSetChanged(
                array_merge($lockedCourseIds, [$courseId])
            );
        }
    }

    private function assertCourseSetIsLocked(array $requiredCourseIds, array $lockedCourseIds): void
    {
        foreach ($requiredCourseIds as $courseId) {
            $this->assertCourseIsLocked((int) $courseId, $lockedCourseIds);
        }
    }

    private function assertAttemptSetUnchanged(Collection $candidates, Collection $locked): void
    {
        $toIdentities = static fn (Collection $attempts): array => $attempts
            ->map(static fn (QuizAttempt $attempt): array => [
                (int) $attempt->id,
                (int) $attempt->quiz_id,
                (int) $attempt->student_profile_id,
                (int) ($attempt->quiz?->course_id ?? 0),
            ])
            ->sort()
            ->values()
            ->all();

        if ($toIdentities($candidates) !== $toIdentities($locked)) {
            throw new AssessmentLockSetChanged([]);
        }

        foreach ($locked as $attempt) {
            if (! $attempt->quiz || (int) $attempt->student_profile_id < 1) {
                throw new AssessmentLockSetChanged([]);
            }
        }
    }

    /**
     * Compare the discovered identity and relationship columns with the
     * models re-read after their row locks are acquired.
     *
     * @param  list<string>  $relationshipColumns
     */
    private function assertRowsUnchanged(
        Collection $candidates,
        Collection $locked,
        array $relationshipColumns
    ): void {
        $toIdentities = static function (Collection $rows) use ($relationshipColumns): array {
            return $rows->map(static function (Model $row) use ($relationshipColumns): array {
                $identity = [(int) $row->getKey()];

                foreach ($relationshipColumns as $column) {
                    $value = $row->getAttribute($column);
                    $identity[] = $value === null ? null : (string) $value;
                }

                return $identity;
            })->sort()->values()->all();
        };

        if ($toIdentities($candidates) !== $toIdentities($locked)) {
            throw new AssessmentLockSetChanged([]);
        }
    }

    /** @return list<int> */
    private function sortedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @return list<array{course_id:int, student_profile_id:int}> */
    private function sortedPairs(array $pairs): array
    {
        $normalized = [];

        foreach ($pairs as $pair) {
            if (! isset($pair['course_id'], $pair['student_profile_id'])) {
                continue;
            }

            $courseId = (int) $pair['course_id'];
            $studentProfileId = (int) $pair['student_profile_id'];

            if ($courseId < 1 || $studentProfileId < 1) {
                continue;
            }

            $normalized[$courseId . ':' . $studentProfileId] = [
                'course_id' => $courseId,
                'student_profile_id' => $studentProfileId,
            ];
        }

        $pairs = array_values($normalized);
        usort($pairs, static fn (array $left, array $right): int =>
            [$left['course_id'], $left['student_profile_id']] <=>
            [$right['course_id'], $right['student_profile_id']]
        );

        return $pairs;
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Course assessment locks must be acquired inside a database transaction.'
            );
        }
    }
}

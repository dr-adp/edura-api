<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstitutionUser;
use App\Models\CourseEnrollment;
use App\Models\QuestionBank;
use App\Models\QuestionOption;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\QuizAttemptGradingService;
use App\Services\CourseAssessmentLockService;
use App\Services\ParentStudentAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\StoreQuizAnswerRequest;
use App\Http\Requests\UpdateQuizAnswerRequest;

class QuizAnswerController extends Controller
{
    public function __construct(
        private QuizAttemptGradingService $quizAttemptGradingService,
        private CourseAssessmentLockService $courseAssessmentLockService,
        private ParentStudentAccessService $parentStudentAccessService
    ) {
    }

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        /*
        |--------------------------------------------------------------------------
        | Scoped Answer Listing
        |--------------------------------------------------------------------------
        */
        $query = QuizAnswer::with($this->answerRelationsFor($user));

        $this->scopeQuizAnswerQuery(
            $query,
            $user
        );

        $answers = $query
            ->latest()
            ->paginate(20);

        $this->sanitizePaginatedAnswersForUser(
            $answers,
            $user
        );

        return response()->json([
            'message' => 'Quiz answers fetched successfully.',
            'data' => $answers,
        ]);
    }

    public function store(StoreQuizAnswerRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */
        $validated = $request->validated();

        $answer = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use ($validated, $user): QuizAnswer {
            $attempt = $this->courseAssessmentLockService
                ->lockAttemptForMutation(
                    (int) $validated['quiz_attempt_id'],
                    (int) $validated['question_bank_id'],
                    isset($validated['question_option_id'])
                        ? (int) $validated['question_option_id']
                        : null
                );

            $this->authorizeQuizAttemptAccess($attempt, $user);
            $this->authorizeQuizAttemptAnswerSubmission($attempt, $user);

            $quizQuestion = $this->resolveQuizQuestion(
                $attempt,
                (int) $validated['question_bank_id']
            );
            $option = $this->resolveQuestionOption(
                $attempt,
                $validated['question_option_id'] ?? null,
                (int) $validated['question_bank_id']
            );

            if ($this->courseAssessmentLockService->lockQuizAnswerForQuestion(
                (int) $attempt->id,
                (int) $validated['question_bank_id']
            )) {
                throw ValidationException::withMessages([
                    'question_bank_id' => 'This question has already been answered for this attempt.',
                ]);
            }

            $marksForQuestion = $quizQuestion->marks ?? 0;
            $isCorrect = (bool) ($option && $option->is_correct);

            $answer = QuizAnswer::create([
                'quiz_attempt_id' => $attempt->id,
                'question_bank_id' => $validated['question_bank_id'],
                'question_option_id' => $validated['question_option_id'] ?? null,
                'answer_text' => $validated['answer_text'] ?? null,
                'is_correct' => $isCorrect,
                'marks_obtained' => $isCorrect ? $marksForQuestion : 0,
            ]);

            $this->recalculateQuizAttempt($attempt);

            return $answer;
            });

        $answer->load($this->answerRelationsFor($user));

        $this->sanitizeAnswerForUser(
            $answer,
            $user
        );

        return response()->json([
            'message' => 'Quiz answer submitted successfully.',
            'data' => $answer,
        ], 201);
    }

    public function show(QuizAnswer $quizAnswer): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $quizAnswer->load($this->answerRelationsFor($user));

        /*
        |--------------------------------------------------------------------------
        | Ownership Check
        |--------------------------------------------------------------------------
        */
        $this->authorizeQuizAnswerAccess(
            $quizAnswer,
            $user
        );

        $this->sanitizeAnswerForUser(
            $quizAnswer,
            $user
        );

        return response()->json([
            'message' => 'Quiz answer fetched successfully.',
            'data' => $quizAnswer,
        ]);
    }

    public function update(
        UpdateQuizAnswerRequest $request,
        QuizAnswer $quizAnswer
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();
        $validated = $request->validated();
        $answerId = (int) $quizAnswer->id;

        $quizAnswer = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use (
            $answerId,
            $validated,
            $user
        ): QuizAnswer {
            $answerIdentity = QuizAnswer::query()->findOrFail($answerId);
            $questionOptionId = array_key_exists('question_option_id', $validated)
                ? ($validated['question_option_id'] === null
                    ? null
                    : (int) $validated['question_option_id'])
                : ($answerIdentity->question_option_id === null
                    ? null
                    : (int) $answerIdentity->question_option_id);
            $attempt = $this->courseAssessmentLockService
                ->lockAttemptForMutation(
                    (int) $answerIdentity->quiz_attempt_id,
                    (int) $answerIdentity->question_bank_id,
                    $questionOptionId
                );

            $quizAnswer = $this->courseAssessmentLockService
                ->lockQuizAnswerForAttempt($answerId, (int) $attempt->id)
                ->load($this->answerRelationsFor($user));
            $quizAnswer->setRelation('quizAttempt', $attempt);

            $this->authorizeQuizAnswerMutation($quizAnswer, $user);

            $quizQuestion = $this->resolveQuizQuestion(
                $attempt,
                (int) $quizAnswer->question_bank_id
            );

        /*
        |--------------------------------------------------------------------------
        | Student Field Protection
        |--------------------------------------------------------------------------
        */
        if ($this->isStudentScoped($user)) {
            unset(
                $validated['marks_obtained'],
                $validated['is_correct']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Staff Evaluation Field Protection
        |--------------------------------------------------------------------------
        */
        if ($this->isStaffEvaluationScoped($user)) {
            unset(
                $validated['question_option_id'],
                $validated['answer_text']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Option Recalculation
        |--------------------------------------------------------------------------
        */
        if (array_key_exists('question_option_id', $validated)) {
            $option = $this->resolveQuestionOption(
                $attempt,
                $validated['question_option_id'],
                (int) $quizAnswer->question_bank_id
            );

            $validated['is_correct'] = false;
            $validated['marks_obtained'] = 0;

            if ($option && $option->is_correct) {
                $validated['is_correct'] = true;
                $validated['marks_obtained'] = $quizQuestion->marks ?? 0;
            }
        }

        if (
            ! $this->isStudentScoped($user) &&
            array_key_exists('is_correct', $validated) &&
            (
                ! array_key_exists('marks_obtained', $validated) ||
                $validated['marks_obtained'] === null
            ) &&
            in_array(
                $quizAnswer->questionBank?->question_type,
                QuestionBank::MANUAL_QUESTION_TYPES,
                true
            )
        ) {
            throw ValidationException::withMessages([
                'marks_obtained' => 'Explicit marks are required to grade a manual answer.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Manual Evaluation Guard
        |--------------------------------------------------------------------------
        */
        if (
            ! $this->isStudentScoped($user) &&
            array_key_exists('marks_obtained', $validated) &&
            $validated['marks_obtained'] > $quizQuestion->marks
        ) {

            throw ValidationException::withMessages([
                'marks_obtained' => 'Marks obtained cannot exceed the question marks.',
            ]);
        }

        if (
            ! $this->isStudentScoped($user) &&
            array_key_exists('marks_obtained', $validated) &&
            $validated['marks_obtained'] !== null &&
            in_array(
                $quizAnswer->questionBank?->question_type,
                QuestionBank::MANUAL_QUESTION_TYPES,
                true
            )
        ) {
            $validated['manually_graded_at'] = now();
        }

            $quizAnswer->update($validated);

            $this->recalculateQuizAttempt($attempt);

            return $quizAnswer
                ->fresh()
                ->load($this->answerRelationsFor($user));
            });

        $this->sanitizeAnswerForUser(
            $quizAnswer,
            $user
        );

        return response()->json([
            'message' => 'Quiz answer updated successfully.',
            'data' => $quizAnswer,
        ]);
    }

    public function destroy(QuizAnswer $quizAnswer): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $answerId = (int) $quizAnswer->id;

        $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use ($answerId, $user): void {
            $answerIdentity = QuizAnswer::query()->findOrFail($answerId);
            $attempt = $this->courseAssessmentLockService
                ->lockAttemptForMutation(
                    (int) $answerIdentity->quiz_attempt_id,
                    (int) $answerIdentity->question_bank_id,
                    $answerIdentity->question_option_id === null
                        ? null
                        : (int) $answerIdentity->question_option_id
                );

            $answer = $this->courseAssessmentLockService
                ->lockQuizAnswerForAttempt($answerId, (int) $attempt->id)
                ->load(['quizAttempt.quiz', 'quizAttempt.studentProfile']);
            $answer->setRelation('quizAttempt', $attempt);

            $this->authorizeQuizAnswerMutation($answer, $user);

            if (
                ! $user->hasRole('super-admin') &&
                ! $user->hasRole('institution-admin') &&
                ! $this->isStudentScoped($user)
            ) {
                abort(403, 'Unauthorized: You cannot delete quiz answers.');
            }

            $answer->delete();
            $this->recalculateQuizAttempt($attempt);
            });

        return response()->json([
            'message' => 'Quiz answer deleted successfully.',
        ]);
    }

    private function scopeQuizAnswerQuery(
        Builder $query,
        User $user
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Super Admin Scope
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('super-admin')) {
            return;
        }

        $query->whereHas(
            'quizAttempt',
            fn (Builder $attemptQuery) => $attemptQuery
                ->institutionallyConsistent()
        );

        /*
        |--------------------------------------------------------------------------
        | Institution Admin Scope
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('institution-admin')) {
            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )
                ->where('status', 'active')
                ->first();

            if (! $institutionUser) {
                abort(
                    403,
                    'Unauthorized: Institution profile not found.'
                );
            }

            $query->whereHas(
                'quizAttempt.studentProfile',
                function (Builder $q) use ($institutionUser) {

                    $q->where(
                        'institution_id',
                        $institutionUser->institution_id
                    );
                }
            )
                ->whereHas(
                    'quizAttempt.quiz.course',
                    function (Builder $q) use ($institutionUser) {

                        $q->where(
                            'institution_id',
                            $institutionUser->institution_id
                        );
                    }
                );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Teacher Scope
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('teacher')) {
            $teacherProfile = $user->teacherProfile;

            if (! $teacherProfile) {
                abort(
                    403,
                    'Unauthorized: Teacher profile not found.'
                );
            }

            $query->whereHas(
                'quizAttempt.quiz',
                function (Builder $q) use ($teacherProfile) {

                    $q->where(
                        'teacher_profile_id',
                        $teacherProfile->id
                    )
                        ->orWhereHas(
                            'course',
                            function (Builder $q) use ($teacherProfile) {

                                $q->where(
                                    'teacher_profile_id',
                                    $teacherProfile->id
                                );
                            }
                        );
                }
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Student Scope
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('student')) {
            $studentProfile = $user->studentProfile;

            if (! $studentProfile) {
                abort(
                    403,
                    'Unauthorized: Student profile not found.'
                );
            }

            $query->whereHas(
                'quizAttempt',
                function (Builder $q) use ($studentProfile) {

                    $q->where(
                        'student_profile_id',
                        $studentProfile->id
                    );
                }
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Parent Scope
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('parent')) {
            $query->whereHas(
                'quizAttempt',
                function (Builder $attemptQuery) use ($user) {
                    $this->parentStudentAccessService->scopeStudentRecords(
                        $attemptQuery,
                        $user,
                        'quiz_attempts.student_profile_id'
                    );
                }
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Unknown Role Protection
        |--------------------------------------------------------------------------
        */
        abort(
            403,
            'Unauthorized role.'
        );
    }

    private function authorizeQuizAnswerAccess(
        QuizAnswer $quizAnswer,
        User $user
    ): void {

        $quizAnswer->loadMissing([
            'quizAttempt.quiz',
            'quizAttempt.studentProfile',
        ]);

        $this->authorizeQuizAttemptAccess(
            $quizAnswer->quizAttempt,
            $user
        );
    }

    private function authorizeQuizAnswerMutation(
        QuizAnswer $quizAnswer,
        User $user
    ): void {

        $quizAnswer->loadMissing([
            'quizAttempt.quiz.course',
            'quizAttempt.studentProfile',
            'questionBank',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Parent Mutation Protection
        |--------------------------------------------------------------------------
        */
        if ($this->isParentScoped($user)) {
            abort(
                403,
                'Unauthorized: Parents can only view their child quiz answers.'
            );
        }

        $this->authorizeQuizAttemptAccess(
            $quizAnswer->quizAttempt,
            $user
        );

        /*
        |--------------------------------------------------------------------------
        | Student Attempt Lock
        |--------------------------------------------------------------------------
        */
        if ($this->isStudentScoped($user)) {
            $this->authorizeQuizAttemptAnswerSubmission(
                $quizAnswer->quizAttempt,
                $user
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Closed Attempt Lock
        |--------------------------------------------------------------------------
        */
        $attemptStatus = $quizAnswer->quizAttempt->status;
        $isClosedAttempt = $attemptStatus === 'cancelled' || (
            $attemptStatus === 'evaluated' &&
            ! $this->isPendingManualAnswer($quizAnswer)
        );

        if (! $user->hasRole('super-admin') && $isClosedAttempt) {

            abort(
                403,
                'This attempt can no longer be modified.'
            );
        }
    }

    private function authorizeQuizAttemptAccess(
        QuizAttempt $attempt,
        User $user
    ): void {

        $attempt->loadMissing([
            'quiz.course',
            'studentProfile',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Super Admin Access
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('super-admin')) {
            return;
        }

        if (!$attempt->hasConsistentInstitutions()) {
            abort(403, 'Course and student must belong to the same institution.');
        }

        /*
        |--------------------------------------------------------------------------
        | Institution Admin Access
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('institution-admin')) {
            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )
                ->where('status', 'active')
                ->first();

            if ($this->institutionCanAccessAttempt($attempt, $institutionUser)) {

                return;
            }

            abort(
                403,
                'Unauthorized institution access.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Teacher Access
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('teacher')) {
            $teacherProfile = $user->teacherProfile;

            if ($this->teacherCanAccessAttempt($attempt, $teacherProfile)) {

                return;
            }

            abort(
                403,
                'Unauthorized: This quiz does not belong to you.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Student Access
        |--------------------------------------------------------------------------
        */
        if ($this->isStudentScoped($user)) {
            $studentProfile = $attempt->studentProfile;

            if (
                $studentProfile &&
                (int) $studentProfile->user_id === (int) $user->id &&
                (int) $studentProfile->id === (int) $attempt->student_profile_id
            ) {

                return;
            }

            abort(
                403,
                'Unauthorized: You can only access your own quiz answers.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Parent Access
        |--------------------------------------------------------------------------
        */
        if ($this->isParentScoped($user)) {
            if (
                $this->parentStudentAccessService->allowsStudent(
                    $user,
                    (int) $attempt->student_profile_id
                )
            ) {

                return;
            }

            abort(
                403,
                'Unauthorized: You can only access your child quiz answers.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Unknown Role Protection
        |--------------------------------------------------------------------------
        */
        abort(
            403,
            'Unauthorized role.'
        );
    }

    private function authorizeQuizAttemptAnswerSubmission(
        QuizAttempt $attempt,
        User $user
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Role Protection
        |--------------------------------------------------------------------------
        */
        if (
            ! $user->hasAnyRole([
                'super-admin',
                'student',
            ])
        ) {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Super Admin Bypass
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('super-admin')) {
            return;
        }

        if (! $this->isStudentScoped($user)) {
            abort(
                403,
                'Unauthorized: Only students can submit quiz answers.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Student Attempt Status
        |--------------------------------------------------------------------------
        */
        if (
            $this->isStudentScoped($user) &&
            $attempt->status !== 'in_progress'
        ) {

            abort(
                403,
                'This attempt can no longer be modified.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Student Enrollment Check
        |--------------------------------------------------------------------------
        */
        if ($this->isStudentScoped($user)) {
            $studentProfile = $attempt->studentProfile;

            if (! $studentProfile) {
                abort(
                    403,
                    'Unauthorized: Student profile not found.'
                );
            }

            if (! $attempt->quiz) {
                abort(
                    403,
                    'Unauthorized quiz attempt.'
                );
            }

            if ((int) $studentProfile->user_id !== (int) $user->id) {
                abort(
                    403,
                    'Unauthorized: You can only submit answers for yourself.'
                );
            }

            $course = $attempt->quiz->course;
            $enrollment = $attempt->getRelation('courseEnrollment');
            $isEnrolled = $enrollment instanceof CourseEnrollment &&
                ! $enrollment->trashed() &&
                (int) $enrollment->course_id === (int) $course->id &&
                (int) $enrollment->student_profile_id === (int) $studentProfile->id &&
                in_array($enrollment->status, ['active', 'completed'], true) &&
                $course->institution_id !== null &&
                $studentProfile->institution_id !== null &&
                (int) $course->institution_id === (int) $studentProfile->institution_id;

            if (! $isEnrolled) {
                abort(403, 'You are not enrolled in this course.');
            }
        }
    }

    private function institutionCanAccessAttempt(
        QuizAttempt $attempt,
        ?InstitutionUser $institutionUser
    ): bool {

        $attempt->loadMissing([
            'quiz.course',
            'studentProfile',
        ]);

        if (
            ! $institutionUser ||
            ! $attempt->studentProfile ||
            ! $attempt->quiz ||
            ! $attempt->quiz->course
        ) {

            return false;
        }

        return (int) $attempt->studentProfile->institution_id ===
            (int) $institutionUser->institution_id &&
            (int) $attempt->quiz->course->institution_id ===
            (int) $institutionUser->institution_id;
    }

    private function teacherCanAccessAttempt(
        QuizAttempt $attempt,
        ?TeacherProfile $teacherProfile
    ): bool {

        $attempt->loadMissing([
            'quiz.course',
        ]);

        if (
            ! $teacherProfile ||
            ! $attempt->quiz
        ) {

            return false;
        }

        if (
            (int) $attempt->quiz->teacher_profile_id ===
            (int) $teacherProfile->id
        ) {

            return true;
        }

        return $attempt->quiz->course &&
            (int) $attempt->quiz->course->teacher_profile_id ===
            (int) $teacherProfile->id;
    }

    private function isStudentScoped(User $user): bool
    {
        return $user->hasRole('student') &&
            ! $user->hasAnyRole([
                'super-admin',
                'institution-admin',
                'teacher',
            ]);
    }

    private function isParentScoped(User $user): bool
    {
        return $user->hasRole('parent') &&
            ! $user->hasAnyRole([
                'super-admin',
                'institution-admin',
                'teacher',
                'student',
            ]);
    }

    private function isStaffEvaluationScoped(User $user): bool
    {
        return ! $user->hasRole('super-admin') &&
            $user->hasAnyRole([
                'institution-admin',
                'teacher',
            ]);
    }

    private function isPendingManualAnswer(QuizAnswer $answer): bool
    {
        return $this->quizAttemptGradingService->isPendingManualAnswer($answer);
    }

    private function resolveQuizQuestion(
        QuizAttempt $attempt,
        int $questionBankId
    ): QuizQuestion {

        $quizQuestion = $attempt->getRelation('lockedQuizQuestion');
        $questionBank = QuestionBank::query()->find($questionBankId);

        if (
            ! $quizQuestion ||
            (int) $quizQuestion->quiz_id !== (int) $attempt->quiz_id ||
            (int) $quizQuestion->question_bank_id !== $questionBankId ||
            ! $questionBank
        ) {
            throw ValidationException::withMessages([
                'question_bank_id' => 'This question does not belong to the selected quiz attempt.',
            ]);
        }

        return $quizQuestion;
    }

    private function resolveQuestionOption(
        QuizAttempt $attempt,
        ?int $questionOptionId,
        int $questionBankId
    ): ?QuestionOption {

        if (! $questionOptionId) {
            return null;
        }

        $option = $attempt->getRelation('lockedQuestionOption');

        if (
            ! $option ||
            (int) $option->id !== $questionOptionId ||
            (int) $option->question_bank_id !== $questionBankId
        ) {
            throw ValidationException::withMessages([
                'question_option_id' => 'Selected option does not belong to this question.',
            ]);
        }

        return $option;
    }

    private function sanitizePaginatedAnswersForUser(
        LengthAwarePaginator $answers,
        User $user
    ): void {

        $answers
            ->getCollection()
            ->each(function (QuizAnswer $answer) use ($user) {

                $this->sanitizeAnswerForUser(
                    $answer,
                    $user
                );
            });
    }

    private function sanitizeAnswerForUser(
        QuizAnswer $answer,
        User $user
    ): QuizAnswer {

        $attempt = $answer->quizAttempt;
        $hasPendingManualAnswers = false;

        if ($this->isLearnerScoped($user) && $attempt) {
            $hasPendingManualAnswers = $this->quizAttemptGradingService
                ->redactPendingAttemptResults($attempt);
        }

        if ($hasPendingManualAnswers) {
            $this->hideAnswerEvaluationFields($answer, true);

            return $answer;
        }

        if (! $this->shouldHideEvaluation($answer, $user)) {
            return $answer;
        }

        $this->hideAnswerEvaluationFields($answer);

        return $answer;
    }

    private function hideAnswerEvaluationFields(
        QuizAnswer $answer,
        bool $hideManualGradingMarker = false
    ): void {
        $hiddenFields = [
            'is_correct',
            'marks_obtained',
        ];

        if ($hideManualGradingMarker) {
            $hiddenFields[] = 'manually_graded_at';
        }

        $answer->makeHidden($hiddenFields);

        if (
            $answer->relationLoaded('questionOption') &&
            $answer->questionOption
        ) {

            $answer->questionOption->makeHidden([
                'is_correct',
            ]);
        }

        if (
            $answer->relationLoaded('questionBank') &&
            $answer->questionBank &&
            $answer->questionBank->relationLoaded('options')
        ) {

            $answer->questionBank->options->each(function (
                QuestionOption $option
            ) {

                $option->makeHidden([
                    'is_correct',
                ]);
            });
        }
    }

    private function answerRelationsFor(User $user): array
    {
        return [
            'quizAttempt' => function (Relation $query) use ($user) {
                if ($this->isLearnerScoped($user)) {
                    $this->quizAttemptGradingService
                        ->withPendingManualAnswersFlag($query);
                }

                $query->with([
                    'quiz.course',
                    'studentProfile.user',
                    'studentProfile.batch',
                ]);
            },
            'questionBank.options',
            'questionOption',
        ];
    }

    private function isLearnerScoped(User $user): bool
    {
        return $this->isStudentScoped($user) || $this->isParentScoped($user);
    }

    private function shouldHideEvaluation(
        QuizAnswer $answer,
        User $user
    ): bool {

        if (
            $user->hasAnyRole([
                'super-admin',
                'institution-admin',
                'teacher',
            ])
        ) {

            return false;
        }

        if (
            ! $user->hasAnyRole([
                'student',
                'parent',
            ])
        ) {

            return false;
        }

        $answer->loadMissing([
            'quizAttempt.quiz',
        ]);

        $attempt = $answer->quizAttempt;
        $quiz = $attempt?->quiz;

        if (! $attempt || ! $quiz) {
            return true;
        }

        if ($attempt->status === 'evaluated') {
            return false;
        }

        return ! (
            $attempt->status === 'submitted' &&
            $quiz->show_result_immediately
        );
    }

    private function recalculateQuizAttempt(QuizAttempt $attempt): void
    {
        $this->quizAttemptGradingService->recalculate($attempt);
    }
}

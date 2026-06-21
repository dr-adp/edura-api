<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstitutionUser;
use App\Models\QuestionOption;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuizAnswerController extends Controller
{
    private const ANSWER_RELATIONS = [
        'quizAttempt.quiz.course',
        'quizAttempt.studentProfile.user',
        'quizAttempt.studentProfile.batch',
        'questionBank.options',
        'questionOption',
    ];

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        /*
        |--------------------------------------------------------------------------
        | Scoped Answer Listing
        |--------------------------------------------------------------------------
        */
        $query = QuizAnswer::with(self::ANSWER_RELATIONS);

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

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */
        $validated = $request->validate([
            'quiz_attempt_id' => [
                'required',
                'exists:quiz_attempts,id',
            ],
            'question_bank_id' => [
                'required',
                'exists:question_banks,id',
                Rule::unique(
                    'quiz_answers',
                    'question_bank_id'
                )->where(
                    'quiz_attempt_id',
                    $request->quiz_attempt_id
                ),
            ],
            'question_option_id' => [
                'nullable',
                'exists:question_options,id',
            ],
            'answer_text' => [
                'nullable',
                'string',
            ],
        ]);

        $attempt = QuizAttempt::with([
            'quiz.course',
            'studentProfile',
        ])->findOrFail(
            $validated['quiz_attempt_id']
        );

        /*
        |--------------------------------------------------------------------------
        | Ownership Check
        |--------------------------------------------------------------------------
        */
        $this->authorizeQuizAttemptAccess(
            $attempt,
            $user
        );

        $this->authorizeQuizAttemptAnswerSubmission(
            $attempt,
            $user
        );

        /*
        |--------------------------------------------------------------------------
        | Quiz Question Check
        |--------------------------------------------------------------------------
        */
        $quizQuestion = $this->resolveQuizQuestion(
            $attempt,
            (int) $validated['question_bank_id']
        );

        /*
        |--------------------------------------------------------------------------
        | Option Integrity Check
        |--------------------------------------------------------------------------
        */
        $option = $this->resolveQuestionOption(
            $validated['question_option_id'] ?? null,
            (int) $validated['question_bank_id']
        );

        $marksForQuestion = $quizQuestion->marks ?? 0;
        $isCorrect = false;
        $marksObtained = 0;

        if ($option && $option->is_correct) {
            $isCorrect = true;
            $marksObtained = $marksForQuestion;
        }

        /*
        |--------------------------------------------------------------------------
        | Create Answer
        |--------------------------------------------------------------------------
        */
        $answer = QuizAnswer::create([
            'quiz_attempt_id' => $validated['quiz_attempt_id'],
            'question_bank_id' => $validated['question_bank_id'],
            'question_option_id' => $validated['question_option_id'] ?? null,
            'answer_text' => $validated['answer_text'] ?? null,
            'is_correct' => $isCorrect,
            'marks_obtained' => $marksObtained,
        ]);

        $this->recalculateQuizAttempt(
            $attempt
        );

        $answer->load(
            self::ANSWER_RELATIONS
        );

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

        $quizAnswer->load(
            self::ANSWER_RELATIONS
        );

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
        Request $request,
        QuizAnswer $quizAnswer
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();

        $quizAnswer->load(
            self::ANSWER_RELATIONS
        );

        /*
        |--------------------------------------------------------------------------
        | Ownership Check
        |--------------------------------------------------------------------------
        */
        $this->authorizeQuizAnswerMutation(
            $quizAnswer,
            $user
        );

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */
        $validated = $request->validate([
            'question_option_id' => [
                'nullable',
                'exists:question_options,id',
            ],
            'answer_text' => [
                'nullable',
                'string',
            ],
            'marks_obtained' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'is_correct' => [
                'boolean',
            ],
        ]);

        $attempt = $quizAnswer->quizAttempt;
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

        $quizAnswer->update(
            $validated
        );

        $this->recalculateQuizAttempt(
            $attempt
        );

        $quizAnswer = $quizAnswer
            ->fresh()
            ->load(
                self::ANSWER_RELATIONS
            );

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

        $quizAnswer->load([
            'quizAttempt.quiz',
            'quizAttempt.studentProfile',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Ownership Check
        |--------------------------------------------------------------------------
        */
        $this->authorizeQuizAnswerMutation(
            $quizAnswer,
            $user
        );

        /*
        |--------------------------------------------------------------------------
        | Delete Role Protection
        |--------------------------------------------------------------------------
        */
        if (
            ! $user->hasRole('super-admin') &&
            ! $user->hasRole('institution-admin') &&
            ! $this->isStudentScoped($user)
        ) {

            abort(
                403,
                'Unauthorized: You cannot delete quiz answers.'
            );
        }

        $attempt = $quizAnswer->quizAttempt;

        $quizAnswer->delete();

        $this->recalculateQuizAttempt(
            $attempt
        );

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

        /*
        |--------------------------------------------------------------------------
        | Institution Admin Scope
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('institution-admin')) {
            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

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
            $parentProfile = $user->parentProfile;

            if (! $parentProfile) {
                abort(
                    403,
                    'Unauthorized: Parent profile not found.'
                );
            }

            $query->whereHas(
                'quizAttempt',
                function (Builder $q) use ($parentProfile) {

                    $q->where(
                        'student_profile_id',
                        $parentProfile->student_profile_id
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
        if (
            ! $user->hasRole('super-admin') &&
            in_array(
                $quizAnswer->quizAttempt->status,
                [
                    'evaluated',
                    'cancelled',
                ],
                true
            )
        ) {

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

        /*
        |--------------------------------------------------------------------------
        | Institution Admin Access
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('institution-admin')) {
            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

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
            $studentProfile = $user->studentProfile;

            if (
                $studentProfile &&
                (int) $studentProfile->id ===
                (int) $attempt->student_profile_id
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
            $parentProfile = $user->parentProfile;

            if (
                $parentProfile &&
                (int) $parentProfile->student_profile_id ===
                (int) $attempt->student_profile_id
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
            $studentProfile = $user->studentProfile;

            if (! $studentProfile) {
                abort(
                    403,
                    'Unauthorized: Student profile not found.'
                );
            }

            $attempt->loadMissing([
                'quiz',
            ]);

            if (! $attempt->quiz) {
                abort(
                    403,
                    'Unauthorized quiz attempt.'
                );
            }

            $isEnrolled = $studentProfile
                ->courseEnrollments()
                ->where(
                    'course_id',
                    $attempt->quiz->course_id
                )
                ->whereIn(
                    'status',
                    [
                        'active',
                        'completed',
                    ]
                )
                ->exists();

            if (! $isEnrolled) {
                abort(
                    403,
                    'You are not enrolled in this course.'
                );
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

    private function resolveQuizQuestion(
        QuizAttempt $attempt,
        int $questionBankId
    ): QuizQuestion {

        $quizQuestion = QuizQuestion::where(
            'quiz_id',
            $attempt->quiz_id
        )
            ->where(
                'question_bank_id',
                $questionBankId
            )
            ->first();

        if (! $quizQuestion) {
            throw ValidationException::withMessages([
                'question_bank_id' => 'This question does not belong to the selected quiz attempt.',
            ]);
        }

        return $quizQuestion;
    }

    private function resolveQuestionOption(
        ?int $questionOptionId,
        int $questionBankId
    ): ?QuestionOption {

        if (! $questionOptionId) {
            return null;
        }

        $option = QuestionOption::where(
            'id',
            $questionOptionId
        )
            ->where(
                'question_bank_id',
                $questionBankId
            )
            ->first();

        if (! $option) {
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

        if (! $this->shouldHideEvaluation($answer, $user)) {
            return $answer;
        }

        /*
        |--------------------------------------------------------------------------
        | Hide Evaluation Fields
        |--------------------------------------------------------------------------
        */
        $answer->makeHidden([
            'is_correct',
            'marks_obtained',
        ]);

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

        return $answer;
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
        $attempt->loadMissing([
            'quiz',
        ]);

        $marksObtained = QuizAnswer::where(
            'quiz_attempt_id',
            $attempt->id
        )->sum(
            'marks_obtained'
        );

        $totalMarks = $attempt->total_marks > 0
            ? $attempt->total_marks
            : 1;

        $percentage = round(
            ($marksObtained / $totalMarks) * 100,
            2
        );

        // FIX: Use PERCENTAGE-based comparison, not raw marks
        $passingMarks = $attempt->quiz->passing_marks ?? 0;
        $passPercentage = $totalMarks > 0
            ? ($passingMarks / $totalMarks) * 100
            : 0;

        $resultStatus = $percentage >= $passPercentage
            ? 'passed'
            : 'failed';

        $attempt->update([
            'marks_obtained' => $marksObtained,
            'percentage' => $percentage,
            'result_status' => $resultStatus,
        ]);
    }
}

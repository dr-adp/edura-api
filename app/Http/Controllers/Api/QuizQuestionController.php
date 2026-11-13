<?php

namespace App\Http\Controllers\Api;

use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Course;
use App\Models\QuestionBank;
use App\Models\InstitutionUser;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\StoreQuizQuestionRequest;
use App\Http\Requests\UpdateQuizQuestionRequest;
use App\Services\CourseAssessmentLockService;
use Illuminate\Validation\ValidationException;

class QuizQuestionController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = QuizQuestion::with([
            'quiz.course',
            'questionBank.options'
        ]);

        /*
    |--------------------------------------------------------------------------
    | Institution Admin
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (!$institutionUser) {

                abort(
                    403,
                    'Institution profile not found.'
                );
            }

            $query->whereHas(
                'quiz.course',
                function ($q) use ($institutionUser) {

                    $q->where(
                        'institution_id',
                        $institutionUser->institution_id
                    );
                }
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Teacher
    |--------------------------------------------------------------------------
    */ elseif ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {

                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            $query->whereHas(
                'quiz.course',
                function ($q) use ($teacherProfile) {

                    $q->where(
                        'teacher_profile_id',
                        $teacherProfile->id
                    );
                }
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    */ elseif (!$user->hasRole('super-admin')) {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        $quizQuestions = $query
            ->orderBy('sort_order')
            ->paginate(20);

        return response()->json([
            'message' => 'Quiz questions fetched successfully.',
            'data' => $quizQuestions,
        ]);
    }

    public function store(StoreQuizQuestionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /*
    |--------------------------------------------------------------------------
    | Quiz Validation
    |--------------------------------------------------------------------------
    */
        $quiz = Quiz::with('course')
            ->findOrFail(
                $validated['quiz_id']
            );

        $this->authorizeQuizQuestionAccess(
            quiz: $quiz
        );

        /*
    |--------------------------------------------------------------------------
    | Question Validation
    |--------------------------------------------------------------------------
    */
        $questionBank = QuestionBank::with('course')
            ->findOrFail(
                $validated['question_bank_id']
            );

        $this->validateQuizQuestionRelations(
            $quiz,
            $questionBank
        );

        $quizId = (int) $quiz->id;
        $questionBankId = (int) $questionBank->id;
        $locks = app(CourseAssessmentLockService::class);
        $quizQuestion = $locks->transactionWithLockSetRetries(function () use (
            $locks,
            $validated,
            $quizId,
            $questionBankId
        ): QuizQuestion {
            $parents = $locks->lockQuizQuestionDefinitionAndCourses(
                null,
                [$quizId],
                [$questionBankId]
            );
            $lockedQuiz = $parents['quizzes']->firstWhere('id', $quizId);
            $lockedQuestionBank = $parents['question_banks']->firstWhere('id', $questionBankId);

            if (!$lockedQuiz || !$lockedQuestionBank) {
                abort(404);
            }

            $this->authorizeQuizQuestionAccess(quiz: $lockedQuiz);
            $this->validateQuizQuestionRelations($lockedQuiz, $lockedQuestionBank);
            $this->assertQuizQuestionLinkDoesNotExist($quizId, $questionBankId);

            $validated['quiz_id'] = $quizId;
            $validated['question_bank_id'] = $questionBankId;
            if (!isset($validated['marks']) || $validated['marks'] === null) {
                $validated['marks'] = $lockedQuestionBank->marks ?? 0;
            }

            $quizQuestion = QuizQuestion::create($validated);
            $this->recalculateQuizTotalMarks($lockedQuiz);

            return $quizQuestion;
        });

        return response()->json([
            'message' => 'Question added to quiz successfully.',
            'data' => $quizQuestion->load([
                'quiz.course',
                'questionBank.options'
            ]),
        ], 201);
    }

    public function show(QuizQuestion $quizQuestion): JsonResponse
    {
        $this->authorizeQuizQuestionAccess(
            quizQuestion: $quizQuestion
        );

        return response()->json([
            'message' => 'Quiz question fetched successfully.',
            'data' => $quizQuestion->load([
                'quiz.course',
                'questionBank.options'
            ]),
        ]);
    }

    public function update(UpdateQuizQuestionRequest $request, QuizQuestion $quizQuestion): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Existing Quiz Question Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeQuizQuestionAccess(
            quizQuestion: $quizQuestion
        );

        $validated = $request->validated();

        $currentQuizId = (int) $quizQuestion->quiz_id;
        $currentQuestionBankId = (int) $quizQuestion->question_bank_id;
        $targetQuizId = isset($validated['quiz_id'])
            ? (int) $validated['quiz_id']
            : $currentQuizId;
        $targetQuestionBankId = isset($validated['question_bank_id'])
            ? (int) $validated['question_bank_id']
            : $currentQuestionBankId;
        $targetQuiz = Quiz::with('course')->findOrFail($targetQuizId);
        $this->authorizeQuizQuestionAccess(quiz: $targetQuiz);
        $targetQuestionBank = QuestionBank::with('course')->findOrFail($targetQuestionBankId);
        $this->validateQuizQuestionRelations($targetQuiz, $targetQuestionBank);

        $quizQuestionId = (int) $quizQuestion->id;
        $locks = app(CourseAssessmentLockService::class);
        $updatedQuizQuestion = $locks->transactionWithLockSetRetries(function () use (
            $locks,
            $validated,
            $quizQuestionId,
            $currentQuizId,
            $currentQuestionBankId,
            $targetQuizId,
            $targetQuestionBankId
        ): QuizQuestion {
            $parents = $locks->lockQuizQuestionDefinitionAndCourses(
                $quizQuestionId,
                [$currentQuizId, $targetQuizId],
                [$currentQuestionBankId, $targetQuestionBankId]
            );
            $lockedLink = $parents['quiz_question'];
            if (!$lockedLink) {
                abort(404);
            }

            $resolvedQuizId = isset($validated['quiz_id'])
                ? $targetQuizId
                : (int) $lockedLink->quiz_id;
            $resolvedQuestionBankId = isset($validated['question_bank_id'])
                ? $targetQuestionBankId
                : (int) $lockedLink->question_bank_id;
            $oldQuiz = $parents['quizzes']->firstWhere('id', (int) $lockedLink->quiz_id);
            $newQuiz = $parents['quizzes']->firstWhere('id', $resolvedQuizId);
            $questionBank = $parents['question_banks']->firstWhere('id', $resolvedQuestionBankId);
            if (!$oldQuiz || !$newQuiz || !$questionBank) {
                abort(404);
            }

            $lockedLink->setRelation('quiz', $oldQuiz->load('course'));
            $lockedLink->setRelation('questionBank', $parents['question_banks']->firstWhere(
                'id',
                (int) $lockedLink->question_bank_id
            )?->load('course'));
            $this->authorizeQuizQuestionAccess(quizQuestion: $lockedLink);
            $this->authorizeQuizQuestionAccess(quiz: $newQuiz);
            $this->validateQuizQuestionRelations($newQuiz, $questionBank);
            $this->assertQuizQuestionLinkDoesNotExist(
                $resolvedQuizId,
                $resolvedQuestionBankId,
                $quizQuestionId
            );

            if (array_key_exists('marks', $validated) && $validated['marks'] === null) {
                $validated['marks'] = $questionBank->marks ?? 0;
            }
            $validated['quiz_id'] = $resolvedQuizId;
            $validated['question_bank_id'] = $resolvedQuestionBankId;

            $lockedLink->update($validated);

            $quizzesToRecalculate = collect([$oldQuiz, $newQuiz])->unique('id');
            foreach ($quizzesToRecalculate as $quizToRecalculate) {
                $this->recalculateQuizTotalMarks($quizToRecalculate);
            }

            return $lockedLink->fresh();
        });

        return response()->json([
            'message' => 'Quiz question updated successfully.',
            'data' => $updatedQuizQuestion
                ->load([
                    'quiz.course',
                    'questionBank.options'
                ]),
        ]);
    }

    public function destroy(QuizQuestion $quizQuestion): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeQuizQuestionAccess(
            quizQuestion: $quizQuestion
        );

        $quizQuestionId = (int) $quizQuestion->id;
        $quizId = (int) $quizQuestion->quiz_id;
        $questionBankId = (int) $quizQuestion->question_bank_id;
        $locks = app(CourseAssessmentLockService::class);
        $locks->transactionWithLockSetRetries(function () use (
            $locks,
            $quizQuestionId,
            $quizId,
            $questionBankId
        ): void {
            $parents = $locks->lockQuizQuestionDefinitionAndCourses(
                $quizQuestionId,
                [$quizId],
                [$questionBankId]
            );
            $lockedLink = $parents['quiz_question'];
            if (!$lockedLink) {
                abort(404);
            }

            $quiz = $parents['quizzes']->firstWhere('id', (int) $lockedLink->quiz_id);
            $questionBank = $parents['question_banks']->firstWhere(
                'id',
                (int) $lockedLink->question_bank_id
            );
            if (!$quiz || !$questionBank) {
                abort(404);
            }
            $lockedLink->setRelation('quiz', $quiz->load('course'));
            $lockedLink->setRelation('questionBank', $questionBank->load('course'));
            $this->authorizeQuizQuestionAccess(quizQuestion: $lockedLink);

            $lockedLink->delete();
            $this->recalculateQuizTotalMarks($quiz);
        });

        return response()->json([
            'message' => 'Quiz question removed successfully.',
        ]);
    }

    private function recalculateQuizTotalMarks(Quiz $quiz): void
    {
        $totalMarks = QuizQuestion::where('quiz_id', $quiz->id)->sum('marks');

        $quiz->update([
            'total_marks' => $totalMarks,
        ]);
    }

    private function assertQuizQuestionLinkDoesNotExist(
        int $quizId,
        int $questionBankId,
        ?int $ignoreId = null
    ): void {
        $exists = QuizQuestion::query()
            ->where('quiz_id', $quizId)
            ->where('question_bank_id', $questionBankId)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'question_bank_id' => 'The question has already been added to this quiz.',
            ]);
        }
    }

    private function authorizeQuizQuestionAccess(
        ?QuizQuestion $quizQuestion = null,
        ?Quiz $quiz = null
    ): void {

        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('super-admin')) {
            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Institution Admin
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (!$institutionUser) {

                abort(
                    403,
                    'Institution profile not found.'
                );
            }

            if ($quizQuestion) {
                $quizQuestion->loadMissing(
                    'quiz.course'
                );
            }

            if ($quiz) {
                $quiz->loadMissing(
                    'course'
                );
            }

            $institutionId =
                $quizQuestion?->quiz?->course?->institution_id
                ?? $quiz?->course?->institution_id;

            if (
                !$institutionId ||
                (int)$institutionId !==
                (int)$institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized institution access.'
                );
            }

            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Teacher
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {

                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            if ($quizQuestion) {
                $quizQuestion->loadMissing(
                    'quiz.course'
                );
            }

            if ($quiz) {
                $quiz->loadMissing(
                    'course'
                );
            }

            $teacherId =
                $quizQuestion?->quiz?->course?->teacher_profile_id
                ?? $quiz?->course?->teacher_profile_id;

            if (
                !$teacherId ||
                (int)$teacherId !==
                (int)$teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized quiz question access.'
                );
            }

            return;
        }

        abort(
            403,
            'Unauthorized role.'
        );
    }

    private function validateQuizQuestionRelations(
        Quiz $quiz,
        QuestionBank $questionBank
    ): void {

        $quiz->loadMissing('course');
        $questionBank->loadMissing('course');

        /*
    |--------------------------------------------------------------------------
    | Same Course Validation
    |--------------------------------------------------------------------------
    */
        if (
            (int)$quiz->course_id !==
            (int)$questionBank->course_id
        ) {

            abort(
                422,
                'Question must belong to the same course as the quiz.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Same Institution Validation
    |--------------------------------------------------------------------------
    */
        if (
            (int)$quiz->course?->institution_id !==
            (int)$questionBank->course?->institution_id
        ) {

            abort(
                422,
                'Question and quiz must belong to the same institution.'
            );
        }
    }
}

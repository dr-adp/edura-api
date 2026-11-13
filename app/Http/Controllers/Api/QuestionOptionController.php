<?php

namespace App\Http\Controllers\Api;

use App\Models\QuestionOption;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Course;
use App\Models\QuestionBank;
use App\Models\InstitutionUser;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\StoreQuestionOptionRequest;
use App\Http\Requests\UpdateQuestionOptionRequest;
use App\Services\CourseAssessmentLockService;
use Illuminate\Support\Facades\DB;

class QuestionOptionController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = QuestionOption::with([
            'questionBank.course'
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
                'questionBank.course',
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
                'questionBank.course',
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

        $options = $query
            ->orderBy('sort_order')
            ->paginate(20);

        return response()->json([
            'message' => 'Question options fetched successfully.',
            'data' => $options,
        ]);
    }

    public function store(StoreQuestionOptionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /*
    |--------------------------------------------------------------------------
    | Question Ownership Validation
    |--------------------------------------------------------------------------
    */
        $questionBank = QuestionBank::with('course')
            ->findOrFail(
                $validated['question_bank_id']
            );

        $this->authorizeQuestionOptionAccess(
            questionBank: $questionBank
        );


        $questionBankId = (int) $questionBank->id;
        $option = app(CourseAssessmentLockService::class)
            ->transactionWithLockSetRetries(function () use ($validated, $questionBankId): QuestionOption {
                $locks = app(CourseAssessmentLockService::class);
                $parents = $locks->lockQuestionBanksAndCourses([$questionBankId]);
                $lockedQuestionBank = $parents['question_banks']->firstWhere('id', $questionBankId);
                if (!$lockedQuestionBank) {
                    abort(404);
                }

                $this->authorizeQuestionOptionAccess(questionBank: $lockedQuestionBank);
                $this->assertQuizQuestionLinksMatchBankCourses(
                    $parents['quiz_questions'],
                    $parents['quizzes'],
                    $parents['question_banks']
                );
                $validated['question_bank_id'] = $questionBankId;

                return QuestionOption::create($validated);
            });

        return response()->json([
            'message' => 'Question option created successfully.',
            'data' => $option->load([
                'questionBank.course'
            ]),
        ], 201);
    }

    public function show(QuestionOption $questionOption): JsonResponse
    {
        $this->authorizeQuestionOptionAccess(
            questionOption: $questionOption
        );

        return response()->json([
            'message' => 'Question option fetched successfully.',
            'data' => $questionOption->load([
                'questionBank.course'
            ]),
        ]);
    }

    public function update(UpdateQuestionOptionRequest $request, QuestionOption $questionOption): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeQuestionOptionAccess(
            questionOption: $questionOption
        );

        $validated = $request->validated();

        $questionOptionId = (int) $questionOption->id;
        $targetQuestionBankId = isset($validated['question_bank_id'])
            ? (int) $validated['question_bank_id']
            : (int) $questionOption->question_bank_id;
        $targetQuestionBank = QuestionBank::with('course')->findOrFail($targetQuestionBankId);
        $this->authorizeQuestionOptionAccess(questionBank: $targetQuestionBank);
        $locks = app(CourseAssessmentLockService::class);
        $updatedOption = $locks->transactionWithLockSetRetries(function () use (
            $locks,
            $validated,
            $questionOptionId,
            $targetQuestionBankId
        ): QuestionOption {
            $parents = $locks->lockQuestionOptionAndCourses(
                $questionOptionId,
                [$targetQuestionBankId]
            );
            $lockedOption = $parents['question_option'];
            $lockedTargetBank = $parents['question_banks']->firstWhere('id', $targetQuestionBankId);
            if (!$lockedOption || !$lockedTargetBank) {
                abort(404);
            }

            $resolvedQuestionBankId = isset($validated['question_bank_id'])
                ? $targetQuestionBankId
                : (int) $lockedOption->question_bank_id;
            $resolvedTargetBank = $parents['question_banks']->firstWhere(
                'id',
                $resolvedQuestionBankId
            );
            if (!$resolvedTargetBank) {
                abort(404);
            }

            $this->authorizeQuestionOptionAccess(questionOption: $lockedOption);
            $this->authorizeQuestionOptionAccess(questionBank: $resolvedTargetBank);
            $this->assertQuizQuestionLinksMatchBankCourses(
                $parents['quiz_questions'],
                $parents['quizzes'],
                $parents['question_banks']
            );

            if (
                (int) $lockedOption->question_bank_id !== $resolvedQuestionBankId &&
                DB::table('quiz_answers')
                    ->where('question_option_id', $questionOptionId)
                    ->exists()
            ) {
                abort(409, 'An option referenced by answer history cannot be moved to another question.');
            }

            $validated['question_bank_id'] = $resolvedQuestionBankId;
            $lockedOption->update($validated);

            return $lockedOption->fresh()->load('questionBank.course');
        });

        return response()->json([
            'message' => 'Question option updated successfully.',
            'data' => $updatedOption,
        ]);
    }

    public function destroy(QuestionOption $questionOption): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeQuestionOptionAccess(
            questionOption: $questionOption
        );

        $questionOptionId = (int) $questionOption->id;
        $locks = app(CourseAssessmentLockService::class);
        $locks->transactionWithLockSetRetries(function () use ($locks, $questionOptionId): void {
            $parents = $locks->lockQuestionOptionAndCourses($questionOptionId);
            $lockedOption = $parents['question_option'];
            if (!$lockedOption) {
                abort(404);
            }
            $this->authorizeQuestionOptionAccess(questionOption: $lockedOption);
            $this->assertQuizQuestionLinksMatchBankCourses(
                $parents['quiz_questions'],
                $parents['quizzes'],
                $parents['question_banks']
            );
            $lockedOption->delete();
        });

        return response()->json([
            'message' => 'Question option deleted successfully.',
        ]);
    }

    private function authorizeQuestionOptionAccess(
        ?QuestionOption $questionOption = null,
        ?QuestionBank $questionBank = null
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
                abort(403, 'Institution profile not found.');
            }

            if ($questionOption) {
                $questionOption->loadMissing(
                    'questionBank.course'
                );
            }

            if ($questionBank) {
                $questionBank->loadMissing(
                    'course'
                );
            }

            $institutionId =
                $questionOption?->questionBank?->course?->institution_id
                ?? $questionBank?->course?->institution_id;

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
                abort(403, 'Teacher profile not found.');
            }

            if ($questionOption) {
                $questionOption->loadMissing(
                    'questionBank.course'
                );
            }

            if ($questionBank) {
                $questionBank->loadMissing(
                    'course'
                );
            }

            $teacherId =
                $questionOption?->questionBank?->course?->teacher_profile_id
                ?? $questionBank?->course?->teacher_profile_id;

            if (
                !$teacherId ||
                (int)$teacherId !==
                (int)$teacherProfile->id
            ) {
                abort(
                    403,
                    'Unauthorized question option access.'
                );
            }

            return;
        }

        abort(
            403,
            'Unauthorized role.'
        );
    }

    private function assertQuizQuestionLinksMatchBankCourses(
        \Illuminate\Support\Collection $links,
        \Illuminate\Support\Collection $quizzes,
        \Illuminate\Support\Collection $questionBanks
    ): void {
        foreach ($links as $link) {
            $quiz = $quizzes->firstWhere('id', (int) $link->quiz_id);
            $questionBank = $questionBanks->firstWhere('id', (int) $link->question_bank_id);

            if (
                !$quiz ||
                !$questionBank ||
                (int) $quiz->course_id !== (int) $questionBank->course_id
            ) {
                abort(422, 'A question option cannot be changed while its quiz and question belong to different courses.');
            }
        }
    }
}

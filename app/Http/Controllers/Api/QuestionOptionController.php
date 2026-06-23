<?php

namespace App\Http\Controllers\Api;

use App\Models\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Course;
use App\Models\QuestionBank;
use App\Models\InstitutionUser;
use Illuminate\Support\Facades\Auth;

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question_bank_id' => ['required', 'exists:question_banks,id'],
            'option_text' => ['required', 'string', 'max:1000'],
            'is_correct' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

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


        $option = QuestionOption::create(
            $validated
        );

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

    public function update(Request $request, QuestionOption $questionOption): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeQuestionOptionAccess(
            questionOption: $questionOption
        );

        $validated = $request->validate([
            'question_bank_id' => ['sometimes', 'exists:question_banks,id'],
            'option_text' => ['sometimes', 'string', 'max:1000'],
            'is_correct' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Target Question Validation
    |--------------------------------------------------------------------------
    */
        if (isset($validated['question_bank_id'])) {

            $questionBank = QuestionBank::with('course')
                ->findOrFail(
                    $validated['question_bank_id']
                );

            $this->authorizeQuestionOptionAccess(
                questionBank: $questionBank
            );
        }

        $questionOption->update(
            $validated
        );

        return response()->json([
            'message' => 'Question option updated successfully.',
            'data' => $questionOption
                ->fresh()
                ->load([
                    'questionBank.course'
                ]),
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

        $questionOption->delete();

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
}

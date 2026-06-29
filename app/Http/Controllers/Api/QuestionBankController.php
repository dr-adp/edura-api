<?php

namespace App\Http\Controllers\Api;

use App\Models\QuestionBank;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\User;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\InstitutionUser;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\StoreQuestionBankRequest;
use App\Http\Requests\UpdateQuestionBankRequest;

class QuestionBankController extends BaseApiController
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = QuestionBank::with([
            'course',
            'lesson'
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
                'course',
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
                'course',
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

        $questions = $query
            ->latest()
            ->paginate(20);

        return $this->successResponse(
            $questions,
            'Question bank fetched successfully.'
        );
    }

    public function store(StoreQuestionBankRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /*
    |--------------------------------------------------------------------------
    | Course Validation
    |--------------------------------------------------------------------------
    */
        $course = Course::findOrFail(
            $validated['course_id']
        );

        $this->authorizeQuestionBankAccess(
            course: $course
        );

        /*
    |--------------------------------------------------------------------------
    | Lesson Validation
    |--------------------------------------------------------------------------
    */
        $this->validateQuestionRelations(
            $validated,
            $course
        );

        $question = QuestionBank::create(
            $validated
        );

        return $this->successResponse(
            $question->load([
                'course',
                'lesson'
            ]),
            'Question created successfully.',
            201
        );
    }

    public function show(QuestionBank $questionBank): JsonResponse
    {
        $this->authorizeQuestionBankAccess(
            questionBank: $questionBank
        );

        return $this->successResponse(
            $questionBank->load([
                'course',
                'lesson'
            ]),
            'Question fetched successfully.'
        );
    }

    public function update(UpdateQuestionBankRequest $request, QuestionBank $questionBank): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Existing Question Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeQuestionBankAccess(
            questionBank: $questionBank
        );

        $validated = $request->validated();

        /*
    |--------------------------------------------------------------------------
    | Target Course Validation
    |--------------------------------------------------------------------------
    */
        $course = isset($validated['course_id'])
            ? Course::findOrFail($validated['course_id'])
            : $questionBank->course;

        $this->authorizeQuestionBankAccess(
            course: $course
        );

        /*
    |--------------------------------------------------------------------------
    | Lesson Validation
    |--------------------------------------------------------------------------
    */
        $this->validateQuestionRelations(
            $validated,
            $course
        );

        $questionBank->update(
            $validated
        );

        return $this->successResponse(
            $questionBank
                ->fresh()
                ->load([
                    'course',
                    'lesson'
                ]),
            'Question updated successfully.'
        );
    }

    public function destroy(QuestionBank $questionBank): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeQuestionBankAccess(
            questionBank: $questionBank
        );

        $questionBank->delete();

        return $this->successResponse(
            null,
            'Question deleted successfully.'
        );
    }

    private function authorizeQuestionBankAccess(
        ?QuestionBank $questionBank = null,
        ?Course $course = null
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

            if ($questionBank) {
                $questionBank->loadMissing('course');
            }

            $institutionId =
                $questionBank?->course?->institution_id
                ?? $course?->institution_id;

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

            if ($questionBank) {
                $questionBank->loadMissing('course');
            }

            $teacherId =
                $questionBank?->course?->teacher_profile_id
                ?? $course?->teacher_profile_id;

            if (
                !$teacherId ||
                (int)$teacherId !==
                (int)$teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized question bank access.'
                );
            }

            return;
        }

        abort(
            403,
            'Unauthorized role.'
        );
    }

    private function validateQuestionRelations(
        array $validated,
        Course $course
    ): void {

        /*
    |--------------------------------------------------------------------------
    | Lesson Validation
    |--------------------------------------------------------------------------
    */
        if (!empty($validated['lesson_id'])) {

            $lesson = Lesson::where(
                'id',
                $validated['lesson_id']
            )
                ->where(
                    'course_id',
                    $course->id
                )
                ->first();

            if (!$lesson) {

                abort(
                    422,
                    'Selected lesson does not belong to the course.'
                );
            }
        }
    }
}

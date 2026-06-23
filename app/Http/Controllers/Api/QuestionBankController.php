<?php

namespace App\Http\Controllers\Api;

use App\Models\QuestionBank;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\InstitutionUser;
use Illuminate\Support\Facades\Auth;

class QuestionBankController extends Controller
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

        return response()->json([
            'message' => 'Question bank fetched successfully.',
            'data' => $questions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],

            'question_text' => ['required', 'string', 'max:1000'],
            'question_description' => ['nullable', 'string'],

            'question_type' => [
                'nullable',
                'in:mcq,true_false,short_answer,long_answer,fill_blank'
            ],

            'difficulty' => [
                'nullable',
                'in:easy,medium,hard'
            ],

            'marks' => ['nullable', 'numeric', 'min:0'],

            'topic' => ['nullable', 'string', 'max:255'],

            'explanation' => ['nullable', 'string'],

            'status' => [
                'nullable',
                'in:active,inactive'
            ],
        ]);

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

        return response()->json([
            'message' => 'Question created successfully.',
            'data' => $question->load([
                'course',
                'lesson'
            ]),
        ], 201);
    }

    public function show(QuestionBank $questionBank): JsonResponse
    {
        $this->authorizeQuestionBankAccess(
            questionBank: $questionBank
        );

        return response()->json([
            'message' => 'Question fetched successfully.',
            'data' => $questionBank->load([
                'course',
                'lesson'
            ]),
        ]);
    }

    public function update(Request $request, QuestionBank $questionBank): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Existing Question Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeQuestionBankAccess(
            questionBank: $questionBank
        );

        $validated = $request->validate([
            'course_id' => ['sometimes', 'exists:courses,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],

            'question_text' => ['sometimes', 'string', 'max:1000'],
            'question_description' => ['nullable', 'string'],

            'question_type' => [
                'nullable',
                'in:mcq,true_false,short_answer,long_answer,fill_blank'
            ],

            'difficulty' => [
                'nullable',
                'in:easy,medium,hard'
            ],

            'marks' => ['nullable', 'numeric', 'min:0'],

            'topic' => ['nullable', 'string', 'max:255'],

            'explanation' => ['nullable', 'string'],

            'status' => [
                'nullable',
                'in:active,inactive'
            ],
        ]);

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

        return response()->json([
            'message' => 'Question updated successfully.',
            'data' => $questionBank
                ->fresh()
                ->load([
                    'course',
                    'lesson'
                ]),
        ]);
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

        return response()->json([
            'message' => 'Question deleted successfully.',
        ]);
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

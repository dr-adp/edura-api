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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quiz_id' => ['required', 'exists:quizzes,id'],
            'question_bank_id' => [
                'required',
                'exists:question_banks,id',
                Rule::unique('quiz_questions', 'question_bank_id')
                    ->where('quiz_id', $request->quiz_id),
            ],
            'marks' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer'],
        ]);

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

        /*
    |--------------------------------------------------------------------------
    | Default Marks
    |--------------------------------------------------------------------------
    */
        if (
            !isset($validated['marks']) ||
            $validated['marks'] === null
        ) {

            $validated['marks'] =
                $questionBank->marks ?? 0;
        }

        $quizQuestion = QuizQuestion::create(
            $validated
        );

        $this->recalculateQuizTotalMarks(
            $quizQuestion->quiz
        );

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

    public function update(Request $request, QuizQuestion $quizQuestion): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Existing Quiz Question Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeQuizQuestionAccess(
            quizQuestion: $quizQuestion
        );

        $validated = $request->validate([
            'quiz_id' => ['sometimes', 'exists:quizzes,id'],
            'question_bank_id' => [
                'sometimes',
                'exists:question_banks,id',
                Rule::unique('quiz_questions', 'question_bank_id')
                    ->where(
                        'quiz_id',
                        $request->quiz_id ?? $quizQuestion->quiz_id
                    )
                    ->ignore($quizQuestion->id),
            ],
            'marks' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Determine Target Quiz
    |--------------------------------------------------------------------------
    */


        $quiz = isset($validated['quiz_id'])
            ? Quiz::with('course')->findOrFail($validated['quiz_id'])
            : $quizQuestion->loadMissing('quiz.course')->quiz;



        $this->authorizeQuizQuestionAccess(
            quiz: $quiz
        );

        /*
    |--------------------------------------------------------------------------
    | Determine Target Question Bank
    |--------------------------------------------------------------------------
    */
        $questionBank = isset($validated['question_bank_id'])
            ? QuestionBank::with('course')->findOrFail($validated['question_bank_id'])
            : $quizQuestion->loadMissing('questionBank.course')->questionBank;

        /*
    |--------------------------------------------------------------------------
    | Cross Validation
    |--------------------------------------------------------------------------
    */
        $this->validateQuizQuestionRelations(
            $quiz,
            $questionBank
        );

        /*
    |--------------------------------------------------------------------------
    | Default Marks From Question Bank
    |--------------------------------------------------------------------------
    */
        if (
            array_key_exists('marks', $validated) &&
            $validated['marks'] === null
        ) {

            $validated['marks'] =
                $questionBank->marks ?? 0;
        }

        $oldQuiz = $quizQuestion->quiz;

        $quizQuestion->update(
            $validated
        );

        /*
    |--------------------------------------------------------------------------
    | Recalculate Totals
    |--------------------------------------------------------------------------
    */
        $this->recalculateQuizTotalMarks(
            $oldQuiz
        );

        $this->recalculateQuizTotalMarks(
            $quizQuestion->fresh()->quiz
        );

        return response()->json([
            'message' => 'Quiz question updated successfully.',
            'data' => $quizQuestion
                ->fresh()
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

        $quiz = $quizQuestion->quiz;

        $quizQuestion->delete();

        $this->recalculateQuizTotalMarks(
            $quiz
        );

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

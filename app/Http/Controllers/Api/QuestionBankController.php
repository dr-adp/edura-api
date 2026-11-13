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
use Illuminate\Support\Facades\DB;
use App\Http\Requests\StoreQuestionBankRequest;
use App\Http\Requests\UpdateQuestionBankRequest;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\QuestionOption;
use App\Services\CourseAssessmentLockService;

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
        $course = Course::findOrFail($validated['course_id']);

        $this->authorizeQuestionBankAccess(
            course: $course
        );

        $question = app(CourseAssessmentLockService::class)
            ->transactionWithLockSetRetries(function () use ($validated): QuestionBank {
                $locks = app(CourseAssessmentLockService::class);
                $courseId = (int) $validated['course_id'];
                $locks->lockCourses([$courseId]);
                $course = Course::findOrFail($courseId);
                $this->authorizeQuestionBankAccess(course: $course);
                $this->validateQuestionRelations($validated, $course);

                return QuestionBank::create($validated);
            });

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

        $targetCourseId = isset($validated['course_id'])
            ? (int) $validated['course_id']
            : (int) $questionBank->course_id;
        $course = Course::findOrFail($targetCourseId);

        $this->authorizeQuestionBankAccess(
            course: $course
        );

        $questionBankId = (int) $questionBank->id;
        $boundCourseId = (int) $questionBank->course_id;
        $locks = app(CourseAssessmentLockService::class);
        $locks->transactionWithLockSetRetries(function () use (
            $locks,
            $validated,
            $questionBankId,
            $boundCourseId,
            $targetCourseId
        ): void {
            $parents = $locks->lockQuestionBanksAndCourses(
                [$questionBankId],
                [$boundCourseId, $targetCourseId]
            );
            $lockedQuestion = $parents['question_banks']->firstWhere('id', $questionBankId);
            if (!$lockedQuestion) {
                abort(404);
            }

            $targetId = isset($validated['course_id'])
                ? (int) $validated['course_id']
                : (int) $lockedQuestion->course_id;
            $course = Course::findOrFail($targetId);
            $this->authorizeQuestionBankAccess(questionBank: $lockedQuestion);
            $this->authorizeQuestionBankAccess(course: $course);
            $this->assertLinkedQuizzesBelongToQuestionCourse(
                $parents['quizzes'],
                $lockedQuestion->course_id
            );

            $effectiveLessonId = array_key_exists('lesson_id', $validated)
                ? $validated['lesson_id']
                : $lockedQuestion->lesson_id;
            $this->validateQuestionRelations(
                ['lesson_id' => $effectiveLessonId],
                $course
            );

            if (
                isset($validated['course_id']) &&
                (int) $lockedQuestion->course_id !== $targetId
            ) {
                if (DB::table('quiz_answers')
                    ->where('question_bank_id', $questionBankId)
                    ->exists()) {
                    abort(409, 'A question with answer history cannot be moved to another course.');
                }

                foreach ($parents['quizzes'] as $linkedQuiz) {
                    if ((int) $linkedQuiz->course_id !== $targetId) {
                        abort(422, 'A question linked to a quiz cannot be moved to another course.');
                    }
                }
            }

            if (
                array_key_exists('question_type', $validated) &&
                $validated['question_type'] !== $lockedQuestion->question_type &&
                DB::table('quiz_answers')->where('question_bank_id', $questionBankId)->exists()
            ) {
                abort(409, 'A question type with answer history cannot be changed.');
            }

            $lockedQuestion->update($validated);
        });

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

        $questionBankId = (int) $questionBank->id;
        $courseId = (int) $questionBank->course_id;
        $locks = app(CourseAssessmentLockService::class);
        $locks->transactionWithLockSetRetries(function () use (
            $locks,
            $questionBankId,
            $courseId
        ): void {
            $parents = $locks->lockQuestionBanksAndCourses(
                [$questionBankId],
                [$courseId]
            );
            $lockedQuestion = $parents['question_banks']->firstWhere('id', $questionBankId);
            if (!$lockedQuestion) {
                abort(404);
            }

            $this->authorizeQuestionBankAccess(questionBank: $lockedQuestion);
            $this->assertLinkedQuizzesBelongToQuestionCourse(
                $parents['quizzes'],
                $lockedQuestion->course_id
            );

            if (DB::table('quiz_answers')
                ->where('question_bank_id', $questionBankId)
                ->exists()) {
                abort(409, 'A question with answer history cannot be deleted.');
            }

            $optionIds = QuestionOption::query()
                ->where('question_bank_id', $questionBankId)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            if ($optionIds !== []) {
                $locks->lockQuestionOptions($optionIds);
            }

            $lockedQuestion->delete();

            foreach ($parents['quizzes'] as $quiz) {
                $this->recalculateQuizTotalMarks($quiz);
            }
        });

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

    private function assertLinkedQuizzesBelongToQuestionCourse(
        \Illuminate\Support\Collection $quizzes,
        int|string $courseId
    ): void {
        foreach ($quizzes as $quiz) {
            if ((int) $quiz->course_id !== (int) $courseId) {
                abort(422, 'Question must belong to the same course as every linked quiz.');
            }
        }
    }

    private function recalculateQuizTotalMarks(Quiz $quiz): void
    {
        $quiz->update([
            'total_marks' => QuizQuestion::query()
                ->where('quiz_id', $quiz->id)
                ->sum('marks'),
        ]);
    }
}

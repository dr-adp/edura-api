<?php

namespace App\Http\Controllers\Api;

use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\User;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\InstitutionUser;
use App\Models\CourseSection;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\StoreQuizRequest;
use App\Http\Requests\UpdateQuizRequest;
use App\Services\CourseAssessmentLockService;

class QuizController extends BaseApiController
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = Quiz::with([
            'course',
            'courseSection',
            'lesson',
            'teacherProfile.user'
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
            )
                ->where('status', 'active')
                ->first();

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

        $quizzes = $query
            ->latest()
            ->paginate(20);

        return $this->successResponse(
            $quizzes,
            'Quizzes fetched successfully.'
        );
    }

    public function store(StoreQuizRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validated();

        /*
    |--------------------------------------------------------------------------
    | Course Validation
    |--------------------------------------------------------------------------
    */
        $course = Course::findOrFail($validated['course_id']);

        $this->authorizeQuizAccess(
            course: $course
        );

        $quiz = app(CourseAssessmentLockService::class)
            ->transactionWithLockSetRetries(function () use ($validated, $user): Quiz {
                $locks = app(CourseAssessmentLockService::class);
                $courseId = (int) $validated['course_id'];

                $locks->lockCourses([$courseId]);
                $course = Course::findOrFail($courseId);
                $this->authorizeQuizAccess(course: $course);

                if ($user->hasRole('teacher')) {
                    $teacherProfile = $user->teacherProfile;
                    if (!$teacherProfile) {
                        abort(403, 'Teacher profile not found.');
                    }
                    $validated['teacher_profile_id'] = $teacherProfile->id;
                }

                if ($user->hasRole('institution-admin')) {
                    $validated['teacher_profile_id'] = $course->teacher_profile_id;
                }

                $this->validateQuizRelations($validated, $course);

                if (empty($validated['teacher_profile_id'])) {
                    $validated['teacher_profile_id'] = $course->teacher_profile_id;
                }

                return Quiz::create($validated);
            });

        return $this->successResponse(
            $quiz->load([
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user'
            ]),
            'Quiz created successfully.',
            201
        );
    }

    public function show(Quiz $quiz): JsonResponse
    {
        $this->authorizeQuizAccess(
            quiz: $quiz
        );

        return $this->successResponse(
            $quiz->load([
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user'
            ]),
            'Quiz fetched successfully.'
        );
    }

    public function update(UpdateQuizRequest $request, Quiz $quiz): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Existing Quiz Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeQuizAccess(
            quiz: $quiz
        );

        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validated();

        /*
    |--------------------------------------------------------------------------
    | Target Course Validation
    |--------------------------------------------------------------------------
    */
        $requestedCourseId = isset($validated['course_id'])
            ? (int) $validated['course_id']
            : (int) $quiz->course_id;
        $course = Course::findOrFail($requestedCourseId);

        $this->authorizeQuizAccess(
            course: $course
        );

        $quizId = (int) $quiz->id;
        $boundCourseId = (int) $quiz->course_id;
        $locks = app(CourseAssessmentLockService::class);
        $updatedQuiz = $locks->transactionWithLockSetRetries(function () use (
            $locks,
            $validated,
            $user,
            $quizId,
            $boundCourseId,
            $requestedCourseId
        ): Quiz {
            $lockedQuiz = $locks->lockQuizDefinitionAndCourses(
                $quizId,
                [$boundCourseId, $requestedCourseId]
            );
            $targetCourseId = isset($validated['course_id'])
                ? (int) $validated['course_id']
                : (int) $lockedQuiz->course_id;
            $course = Course::findOrFail($targetCourseId);

            $this->authorizeQuizAccess(quiz: $lockedQuiz);
            $this->authorizeQuizAccess(course: $course);

            if ($user->hasRole('teacher')) {
                $teacherProfile = $user->teacherProfile;
                if (!$teacherProfile) {
                    abort(403, 'Teacher profile not found.');
                }
                $validated['teacher_profile_id'] = $teacherProfile->id;
            }

            if ($user->hasRole('institution-admin')) {
                $validated['teacher_profile_id'] = $course->teacher_profile_id;
            }

            $this->validateQuizRelations($validated, $course);
            $effectiveRelations = array_merge(
                [
                    'course_section_id' => $lockedQuiz->course_section_id,
                    'lesson_id' => $lockedQuiz->lesson_id,
                ],
                array_intersect_key($validated, array_flip(['course_section_id', 'lesson_id']))
            );
            $this->validateQuizRelations($effectiveRelations, $course);

            if ((int) $lockedQuiz->course_id !== $targetCourseId) {
                foreach ($lockedQuiz->lockedDefinitionQuestionLinks as $questionLink) {
                    if ((int) $questionLink->questionBank?->course_id !== $targetCourseId) {
                        abort(422, 'A quiz with linked questions cannot be moved to a different course.');
                    }
                }
            }

            if (!isset($validated['teacher_profile_id'])) {
                $validated['teacher_profile_id'] = $course->teacher_profile_id;
            }

            $lockedQuiz->update($validated);

            return $lockedQuiz->fresh();
        });

        return $this->successResponse(
            $updatedQuiz
                ->load([
                    'course',
                    'courseSection',
                    'lesson',
                    'teacherProfile.user'
                ]),
            'Quiz updated successfully.'
        );
    }

    public function destroy(Quiz $quiz): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeQuizAccess(
            quiz: $quiz
        );

        $quizId = (int) $quiz->id;
        $courseId = (int) $quiz->course_id;
        $locks = app(CourseAssessmentLockService::class);
        $locks->transactionWithLockSetRetries(function () use ($locks, $quizId, $courseId): void {
            $lockedQuiz = $locks->lockQuizDefinitionAndCourses($quizId, [$courseId]);
            $this->authorizeQuizAccess(quiz: $lockedQuiz);
            $lockedQuiz->delete();
        });

        return $this->successResponse(
            null,
            'Quiz deleted successfully.'
        );
    }

    private function authorizeQuizAccess(
        ?Quiz $quiz = null,
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
            )
                ->where('status', 'active')
                ->first();

            if (!$institutionUser) {

                abort(
                    403,
                    'Institution profile not found.'
                );
            }

            if ($quiz) {
                $quiz->loadMissing('course');
            }

            $institutionId =
                $quiz?->course?->institution_id
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

            if ($quiz) {
                $quiz->loadMissing('course');
            }

            $teacherId =
                $quiz?->course?->teacher_profile_id
                ?? $course?->teacher_profile_id;

            if (
                !$teacherId ||
                (int)$teacherId !==
                (int)$teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized quiz access.'
                );
            }

            return;
        }

        abort(
            403,
            'Unauthorized role.'
        );
    }

    private function validateQuizRelations(
        array $validated,
        Course $course
    ): void {

        /*
    |--------------------------------------------------------------------------
    | Course Section Validation
    |--------------------------------------------------------------------------
    */
        if (!empty($validated['course_section_id'])) {

            $section = CourseSection::where(
                'id',
                $validated['course_section_id']
            )
                ->where(
                    'course_id',
                    $course->id
                )
                ->first();

            if (!$section) {

                abort(
                    422,
                    'Selected course section does not belong to the course.'
                );
            }
        }

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

        /*
    |--------------------------------------------------------------------------
    | Passing Marks Validation
    |--------------------------------------------------------------------------
    */
        $totalMarks = $validated['total_marks'] ?? null;
        $passingMarks = $validated['passing_marks'] ?? null;

        if (
            $totalMarks !== null &&
            $passingMarks !== null &&
            $passingMarks > $totalMarks
        ) {

            abort(
                422,
                'Passing marks cannot exceed total marks.'
            );
        }
    }
}

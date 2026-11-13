<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AssessmentLockSetChanged;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\User;
use App\Models\Course;
use App\Models\InstitutionUser;
use App\Models\TeacherProfile;
use App\Services\CourseEnrollmentService;
use App\Services\CourseAssessmentLockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\StoreLessonRequest;
use App\Http\Requests\UpdateLessonRequest;

class LessonController extends BaseApiController
{
    public function __construct(
        private CourseEnrollmentService $courseEnrollmentService,
        private CourseAssessmentLockService $courseAssessmentLockService
    ) {
    }

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = Lesson::with([
            'course',
            'courseSection'
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

        $lessons = $query
            ->orderBy('sort_order')
            ->paginate(20);

        return $this->successResponse(
            $lessons,
            'Lessons fetched successfully.'
        );
    }

    public function store(StoreLessonRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $lesson = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use ($validated): Lesson {
                $courseId = (int) $validated['course_id'];
                $course = $this->courseAssessmentLockService->lockCourse(
                    $courseId,
                    withTrashed: false
                );

                $this->authorizeLessonAccess(course: $course);
                $this->validateLessonSection(
                    $course,
                    $validated['course_section_id'] ?? null
                );

                $lesson = Lesson::create($validated);

                if ($lesson->status === 'published') {
                    $this->courseEnrollmentService
                        ->recalculateCourseEnrollments((int) $lesson->course_id);
                }

                return $lesson->load(['course', 'courseSection']);
            });

        return $this->successResponse(
            $lesson->load([
                'course',
                'courseSection'
            ]),
            'Lesson created successfully.',
            201
        );
    }

    public function show(Lesson $lesson): JsonResponse
    {
        $this->authorizeLessonAccess(
            lesson: $lesson
        );

        return $this->successResponse(
            $lesson->load([
                'course',
                'courseSection'
            ]),
            'Lesson fetched successfully.'
        );
    }

    public function update(UpdateLessonRequest $request, Lesson $lesson): JsonResponse
    {
        $validated = $request->validated();

        $lessonId = (int) $lesson->getKey();
        $requestedCourseId = isset($validated['course_id'])
            ? (int) $validated['course_id']
            : null;

        $updatedLesson = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use (
                $lessonId,
                $requestedCourseId,
                $validated
            ): Lesson {
                $discoveredCourseId = $this->discoverActiveLessonCourseId($lessonId);
                $targetCourseId = $requestedCourseId ?? $discoveredCourseId;
                $lockedCourses = $this->lockActiveCourses([
                    $discoveredCourseId,
                    $targetCourseId,
                ]);
                $lockedLesson = $this->courseAssessmentLockService
                    ->lockLesson($lessonId, withTrashed: false);
                $currentCourseId = (int) $lockedLesson->course_id;

                if ($currentCourseId !== $discoveredCourseId) {
                    throw new AssessmentLockSetChanged([
                        $discoveredCourseId,
                        $targetCourseId,
                        $currentCourseId,
                    ]);
                }

                $currentCourse = $lockedCourses[$currentCourseId] ?? null;
                $targetCourse = $lockedCourses[$targetCourseId] ?? null;

                if (!$currentCourse || !$targetCourse) {
                    throw new AssessmentLockSetChanged([
                        $discoveredCourseId,
                        $targetCourseId,
                        $currentCourseId,
                    ]);
                }

                $lockedLesson->setRelation('course', $currentCourse);
                $this->authorizeLessonAccess(
                    lesson: $lockedLesson,
                    course: $currentCourse
                );
                $this->authorizeLessonAccess(course: $targetCourse);

                $attributes = $validated;
                $courseChanged = $currentCourseId !== $targetCourseId;
                $sectionFieldProvided = array_key_exists(
                    'course_section_id',
                    $attributes
                );
                $targetSectionId = $sectionFieldProvided
                    ? $this->nullableId($attributes['course_section_id'])
                    : $this->nullableId($lockedLesson->course_section_id);

                if (
                    $courseChanged &&
                    !$sectionFieldProvided &&
                    $targetSectionId !== null &&
                    !$this->sectionBelongsToCourse($targetSectionId, $targetCourseId)
                ) {
                    // Preserve the existing behavior of clearing a foreign section
                    // when moving a lesson without selecting a replacement.
                    $attributes['course_section_id'] = null;
                    $targetSectionId = null;
                }

                if (
                    $targetSectionId !== null &&
                    ($sectionFieldProvided || $courseChanged) &&
                    !$this->sectionBelongsToCourse($targetSectionId, $targetCourseId)
                ) {
                    abort(
                        422,
                        'Selected course section does not belong to the course.'
                    );
                }

                if (
                    $courseChanged &&
                    $this->hasIncompatibleLessonDependents($lessonId, $targetCourseId)
                ) {
                    abort(
                        422,
                        'Attached assessments and live classes must belong to the target course before moving this lesson.'
                    );
                }

                $originalCourseId = $currentCourseId;
                $wasPublished = $lockedLesson->status === 'published';
                $lockedLesson->update($attributes);
                $isPublished = $lockedLesson->status === 'published';
                $newCourseId = (int) $lockedLesson->course_id;
                $publishedLessonSetChanged = $wasPublished !== $isPublished
                    || ($wasPublished && $isPublished && $originalCourseId !== $newCourseId);

                if ($publishedLessonSetChanged) {
                    $affectedCourseIds = [];

                    if ($wasPublished) {
                        $affectedCourseIds[] = $originalCourseId;
                    }

                    if ($isPublished) {
                        $affectedCourseIds[] = $newCourseId;
                    }

                    foreach (array_unique($affectedCourseIds) as $courseId) {
                        $this->courseEnrollmentService
                            ->recalculateCourseEnrollments((int) $courseId);
                    }
                }

                return $lockedLesson->fresh()->load(['course', 'courseSection']);
            });

        return $this->successResponse(
            $updatedLesson,
            'Lesson updated successfully.'
        );
    }

    public function destroy(Lesson $lesson): JsonResponse
    {
        $lessonId = (int) $lesson->getKey();

        $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use ($lessonId): void {
                $discoveredCourseId = $this->discoverActiveLessonCourseId($lessonId);
                $lockedCourses = $this->lockActiveCourses([$discoveredCourseId]);
                $lockedLesson = $this->courseAssessmentLockService
                    ->lockLesson($lessonId, withTrashed: false);
                $currentCourseId = (int) $lockedLesson->course_id;

                if ($currentCourseId !== $discoveredCourseId) {
                    throw new AssessmentLockSetChanged([
                        $discoveredCourseId,
                        $currentCourseId,
                    ]);
                }

                $course = $lockedCourses[$currentCourseId] ?? null;

                if (!$course) {
                    throw new AssessmentLockSetChanged([
                        $discoveredCourseId,
                        $currentCourseId,
                    ]);
                }

                $lockedLesson->setRelation('course', $course);
                $this->authorizeLessonAccess(
                    lesson: $lockedLesson,
                    course: $course
                );

                $wasPublished = $lockedLesson->status === 'published';
                $lockedLesson->delete();

                if ($wasPublished) {
                    $this->courseEnrollmentService
                        ->recalculateCourseEnrollments($currentCourseId);
                }
            });

        return $this->successResponse(
            null,
            'Lesson deleted successfully.'
        );
    }

    /** @return array<int, Course> */
    private function lockActiveCourses(array $courseIds): array
    {
        $courseIds = array_values(array_unique(array_map('intval', $courseIds)));
        sort($courseIds, SORT_NUMERIC);
        $this->courseAssessmentLockService->lockCourses($courseIds);

        $courses = Course::query()
            ->whereIn('id', $courseIds)
            ->get()
            ->keyBy(static fn (Course $course): int => (int) $course->getKey());

        foreach ($courseIds as $courseId) {
            if (!$courses->has($courseId)) {
                abort(404, 'Course not found.');
            }
        }

        return $courses->all();
    }

    private function discoverActiveLessonCourseId(int $lessonId): int
    {
        $courseId = DB::table('lessons')
            ->where('id', $lessonId)
            ->whereNull('deleted_at')
            ->value('course_id');

        if ($courseId === null) {
            abort(404, 'Lesson not found.');
        }

        return (int) $courseId;
    }

    private function validateLessonSection(Course $course, mixed $sectionId): void
    {
        if (
            $sectionId !== null &&
            !$this->sectionBelongsToCourse((int) $sectionId, (int) $course->id)
        ) {
            abort(
                422,
                'Selected course section does not belong to the course.'
            );
        }
    }

    private function sectionBelongsToCourse(int $sectionId, int $courseId): bool
    {
        return DB::table('course_sections')
            ->where('id', $sectionId)
            ->where('course_id', $courseId)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Query raw tables so retained soft-deleted references also constrain a
     * course move; dependent definitions and live classes are never rewritten.
     */
    private function hasIncompatibleLessonDependents(
        int $lessonId,
        int $targetCourseId
    ): bool {
        foreach ([
            'assignments',
            'quizzes',
            'question_banks',
            'live_classes',
        ] as $table) {
            if (
                DB::table($table)
                    ->where('lesson_id', $lessonId)
                    ->where('course_id', '!=', $targetCourseId)
                    ->exists()
            ) {
                return true;
            }
        }

        return false;
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function authorizeLessonAccess(
        ?Lesson $lesson = null,
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

            if ($lesson) {
                $lesson->loadMissing('course');
            }

            $institutionId =
                $lesson?->course?->institution_id
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

            $teacherProfile = TeacherProfile::where(
                'user_id',
                $user->id
            )->first();

            if (!$teacherProfile) {

                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            if ($lesson) {
                $lesson->loadMissing('course');
            }

            $teacherId =
                $lesson?->course?->teacher_profile_id
                ?? $course?->teacher_profile_id;

            if (
                !$teacherId ||
                (int)$teacherId !==
                (int)$teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized lesson access.'
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

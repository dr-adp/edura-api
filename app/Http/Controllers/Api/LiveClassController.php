<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AssessmentLockSetChanged;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\Course;
use App\Models\InstitutionUser;
use App\Models\LiveClass;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CourseAssessmentLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\StoreLiveClassRequest;
use App\Http\Requests\UpdateLiveClassRequest;

class LiveClassController extends BaseApiController
{
    public function __construct(
        private CourseAssessmentLockService $courseAssessmentLockService
    ) {
    }

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = LiveClass::with([
            'course',
            'teacherProfile.user',
        ]);

        /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('super-admin')) {

            // Full access

        }

        /*
    |--------------------------------------------------------------------------
    | Institution Admin
    |--------------------------------------------------------------------------
    */ elseif ($user->hasRole('institution-admin')) {

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

            $query->where(
                'institution_id',
                $institutionUser->institution_id
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Teacher
    |--------------------------------------------------------------------------
    */ elseif ($user->hasRole('teacher')) {

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

            $query->where(
                'teacher_profile_id',
                $teacherProfile->id
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Student / Parent
    |--------------------------------------------------------------------------
    */ else {

            abort(
                403,
                'Unauthorized.'
            );
        }

        $liveClasses = $query
            ->latest()
            ->paginate(20);

        return $this->successResponse(
            $liveClasses,
            'Live classes fetched successfully.'
        );
    }

    public function store(StoreLiveClassRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validated();

        $liveClass = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use ($validated, $user): LiveClass {
                $attributes = $validated;
                $course = $this->courseAssessmentLockService->lockCourse(
                    (int) $attributes['course_id'],
                    withTrashed: false
                );

                $attributes['institution_id'] = $course->institution_id;
                $this->authorizeCourseManagement($course, $user, $attributes);
                $this->validateLiveClassRelationships(
                    $course,
                    $attributes['course_section_id'] ?? null,
                    $attributes['lesson_id'] ?? null
                );

                return LiveClass::create($attributes);
            });

        return $this->successResponse(
            $liveClass->load([
                'institution',
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user',
                'batch',
            ]),
            'Live class created successfully.',
            201
        );
    }

    public function show(
        LiveClass $liveClass
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeLiveClassAccess(
            $liveClass
        );

        return $this->successResponse(
            $liveClass->load([
                'course',
                'teacherProfile.user',
            ]),
            'Live class fetched successfully.'
        );
    }

    public function update(
        UpdateLiveClassRequest $request,
        LiveClass $liveClass
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validated();
        $liveClassId = (int) $liveClass->getKey();
        $requestedCourseId = isset($validated['course_id'])
            ? (int) $validated['course_id']
            : null;

        $updatedLiveClass = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use (
                $liveClassId,
                $requestedCourseId,
                $validated,
                $user
            ): LiveClass {
                $discoveredCourseId = $this->discoverActiveLiveClassCourseId($liveClassId);
                $targetCourseId = $requestedCourseId ?? $discoveredCourseId;
                $lockedCourses = $this->lockActiveCourses([
                    $discoveredCourseId,
                    $targetCourseId,
                ]);
                $lockedLiveClass = $this->courseAssessmentLockService
                    ->lockLiveClass($liveClassId, withTrashed: false);
                $currentCourseId = (int) $lockedLiveClass->course_id;

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

                $this->authorizeLiveClassManagement(
                    $lockedLiveClass,
                    $currentCourse
                );

                $attributes = $validated;
                if ($requestedCourseId !== null) {
                    $this->authorizeCourseManagement(
                        $targetCourse,
                        $user,
                        $attributes
                    );
                }

                $attributes['institution_id'] = $targetCourse->institution_id;
                $targetSectionId = array_key_exists('course_section_id', $attributes)
                    ? $this->nullableId($attributes['course_section_id'])
                    : $this->nullableId($lockedLiveClass->course_section_id);
                $targetLessonId = array_key_exists('lesson_id', $attributes)
                    ? $this->nullableId($attributes['lesson_id'])
                    : $this->nullableId($lockedLiveClass->lesson_id);

                if (
                    $requestedCourseId !== null ||
                    array_key_exists('course_section_id', $attributes) ||
                    array_key_exists('lesson_id', $attributes)
                ) {
                    $this->validateLiveClassRelationships(
                        $targetCourse,
                        $targetSectionId,
                        $targetLessonId
                    );
                }

                $lockedLiveClass->fill($attributes);
                $lockedLiveClass->save();

                return $lockedLiveClass->fresh()->load([
                    'institution',
                    'course',
                    'courseSection',
                    'lesson',
                    'teacherProfile.user',
                    'batch',
                ]);
            });

        return $this->successResponse(
            $updatedLiveClass,
            'Live class updated successfully.'
        );
    }

    public function destroy(
        LiveClass $liveClass
    ): JsonResponse {
        $liveClassId = (int) $liveClass->getKey();

        $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use ($liveClassId): void {
                $discoveredCourseId = $this->discoverActiveLiveClassCourseId($liveClassId);
                $lockedCourses = $this->lockActiveCourses([$discoveredCourseId]);
                $lockedLiveClass = $this->courseAssessmentLockService
                    ->lockLiveClass($liveClassId, withTrashed: false);
                $currentCourseId = (int) $lockedLiveClass->course_id;

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

                $this->authorizeLiveClassManagement($lockedLiveClass, $course);

                if ($lockedLiveClass->attendances()->exists()) {
                    abort(
                        422,
                        'Live class cannot be deleted because attendance records exist.'
                    );
                }

                $lockedLiveClass->delete();
            });

        return $this->successResponse(
            null,
            'Live class deleted successfully.'
        );
    }

    private function authorizeLiveClassAccess(
        LiveClass $liveClass,
        ?Course $course = null
    ): void {

        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$institutionUser ||
                (int) ($course?->institution_id ?? $liveClass->institution_id) !==
                (int) $institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized institution access.'
                );
            }

            return;
        }

        if ($user->hasRole('teacher')) {

            $teacherProfile = TeacherProfile::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$teacherProfile ||
                (int) $liveClass->teacher_profile_id !==
                (int) $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized live class.'
                );
            }

            return;
        }

        if (
            $user->hasAnyRole([
                'student',
                'parent'
            ])
        ) {

            abort(
                403,
                'Unauthorized.'
            );
        }

        abort(
            403,
            'Unauthorized.'
        );
    }

    private function authorizeLiveClassManagement(
        LiveClass $liveClass,
        ?Course $course = null
    ): void {

        $this->authorizeLiveClassAccess(
            $liveClass,
            $course
        );
    }

    private function authorizeCourseManagement(
        Course $course,
        User $user,
        array &$attributes
    ): void {
        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$institutionUser ||
                (int) $institutionUser->institution_id !== (int) $course->institution_id
            ) {
                abort(403, 'Unauthorized institution access.');
            }

            return;
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$teacherProfile ||
                (int) $course->teacher_profile_id !== (int) $teacherProfile->id
            ) {
                abort(403, 'Unauthorized teacher access.');
            }

            $attributes['teacher_profile_id'] = $teacherProfile->id;

            return;
        }

        abort(403, 'Unauthorized.');
    }

    private function validateLiveClassRelationships(
        Course $course,
        mixed $courseSectionId,
        mixed $lessonId
    ): void {
        if (
            $courseSectionId !== null &&
            !DB::table('course_sections')
                ->where('id', $courseSectionId)
                ->where('course_id', $course->id)
                ->whereNull('deleted_at')
                ->exists()
        ) {
            abort(422, 'Selected course section does not belong to the course.');
        }

        if (
            $lessonId !== null &&
            !DB::table('lessons')
                ->where('id', $lessonId)
                ->where('course_id', $course->id)
                ->whereNull('deleted_at')
                ->exists()
        ) {
            abort(422, 'Selected lesson does not belong to the course.');
        }
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

    private function discoverActiveLiveClassCourseId(int $liveClassId): int
    {
        $courseId = DB::table('live_classes')
            ->where('id', $liveClassId)
            ->whereNull('deleted_at')
            ->value('course_id');

        if ($courseId === null) {
            abort(404, 'Live class not found.');
        }

        return (int) $courseId;
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

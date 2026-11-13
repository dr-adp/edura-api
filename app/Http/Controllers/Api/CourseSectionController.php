<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AssessmentLockSetChanged;
use App\Models\CourseSection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\Course;
use App\Models\InstitutionUser;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\StoreCourseSectionRequest;
use App\Http\Requests\UpdateCourseSectionRequest;
use App\Services\CourseAssessmentLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseSectionController extends BaseApiController
{
    public function __construct(
        private readonly CourseAssessmentLockService $courseAssessmentLockService
    ) {
    }

    private function authorizeCourseSectionAccess(
        CourseSection $courseSection,
        ?Course $course = null
    ): void {

        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        $course ??= $courseSection->course;

        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$institutionUser ||
                (int) $course->institution_id !==
                (int) $institutionUser->institution_id
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
                (int) $course->teacher_profile_id !==
                (int) $teacherProfile->id
            ) {
                abort(403, 'Unauthorized course section.');
            }

            return;
        }

        abort(403, 'Unauthorized.');
    }
    private function authorizeCourseSectionManagement(
        Course $course
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
                (int) $course->institution_id !==
                (int) $institutionUser->institution_id
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
                (int) $course->teacher_profile_id !==
                (int) $teacherProfile->id
            ) {
                abort(403, 'Unauthorized course.');
            }

            return;
        }

        abort(403, 'Unauthorized.');
    }

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = CourseSection::with([
            'course',
        ]);

        if ($user->hasRole('super-admin')) {

            // Full access

        } elseif ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (!$institutionUser) {
                abort(403, 'Institution profile not found.');
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
        } elseif ($user->hasRole('teacher')) {

            $teacherProfile = TeacherProfile::where(
                'user_id',
                $user->id
            )->first();

            if (!$teacherProfile) {
                abort(403, 'Teacher profile not found.');
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
        } else {

            abort(403, 'Unauthorized.');
        }

        $courseSections = $query
            ->orderBy('sort_order')
            ->paginate(20);

        return $this->successResponse(
            $courseSections,
            'Course sections fetched successfully.'
        );
    }

    public function store(StoreCourseSectionRequest $request): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validated();

        $courseSection = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use ($validated): CourseSection {
                $courseId = (int) $validated['course_id'];
                $lockedCourses = $this->lockActiveCourses([$courseId]);
                $course = $lockedCourses[$courseId];

                $this->authorizeCourseSectionManagement($course);

                return CourseSection::create($validated)->load('course');
            });

        return $this->successResponse(
            $courseSection->load([
                'course',
            ]),
            'Course section created successfully.',
            201
        );
    }

    public function show(
        CourseSection $courseSection
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeCourseSectionAccess(
            $courseSection
        );

        return $this->successResponse(
            $courseSection->load([
                'course',
            ]),
            'Course section fetched successfully.'
        );
    }

    public function update(
        UpdateCourseSectionRequest $request,
        CourseSection $courseSection
    ): JsonResponse {
        $validated = $request->validated();
        $courseSectionId = (int) $courseSection->getKey();
        $requestedCourseId = isset($validated['course_id'])
            ? (int) $validated['course_id']
            : null;

        $updatedSection = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use (
                $courseSectionId,
                $requestedCourseId,
                $validated
            ): CourseSection {
                // This read only discovers the lock set. The section is reread
                // under lock after all discovered course locks are held.
                $discoveredCourseId = $this->discoverActiveSectionCourseId(
                    $courseSectionId
                );
                $targetCourseId = $requestedCourseId ?? $discoveredCourseId;
                $lockedCourses = $this->lockActiveCourses([
                    $discoveredCourseId,
                    $targetCourseId,
                ]);

                $lockedSection = $this->courseAssessmentLockService
                    ->lockCourseSection($courseSectionId, withTrashed: false);
                $currentCourseId = (int) $lockedSection->course_id;

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

                $this->authorizeCourseSectionAccess(
                    $lockedSection,
                    $currentCourse
                );

                if ($requestedCourseId !== null) {
                    $this->authorizeCourseSectionManagement($targetCourse);
                }

                if (
                    $targetCourseId !== $currentCourseId &&
                    $this->sectionHasDependentRecords($courseSectionId)
                ) {
                    throw ValidationException::withMessages([
                        'course_id' => 'A course section with lessons, assignments, quizzes, or live classes cannot be moved to another course.',
                    ]);
                }

                $lockedSection->update($validated);

                return $lockedSection->fresh()->load('course');
            });

        return $this->successResponse(
            $updatedSection,
            'Course section updated successfully.'
        );
    }

    public function destroy(
        CourseSection $courseSection
    ): JsonResponse {
        $courseSectionId = (int) $courseSection->getKey();

        $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use ($courseSectionId): void {
                $discoveredCourseId = $this->discoverActiveSectionCourseId(
                    $courseSectionId
                );
                $lockedCourses = $this->lockActiveCourses([$discoveredCourseId]);
                $lockedSection = $this->courseAssessmentLockService
                    ->lockCourseSection($courseSectionId, withTrashed: false);
                $currentCourseId = (int) $lockedSection->course_id;

                if ($currentCourseId !== $discoveredCourseId) {
                    throw new AssessmentLockSetChanged([
                        $discoveredCourseId,
                        $currentCourseId,
                    ]);
                }

                $currentCourse = $lockedCourses[$currentCourseId] ?? null;

                if (!$currentCourse) {
                    throw new AssessmentLockSetChanged([
                        $discoveredCourseId,
                        $currentCourseId,
                    ]);
                }

                $this->authorizeCourseSectionAccess(
                    $lockedSection,
                    $currentCourse
                );

                if ($this->sectionHasDependentRecords($courseSectionId)) {
                    abort(
                        422,
                        'Course section cannot be deleted because it has lessons, assignments, quizzes, or live classes.'
                    );
                }

                // Preserve soft-delete behavior and all related records.
                $lockedSection->delete();
            });

        return $this->successResponse(
            null,
            'Course section deleted successfully.'
        );
    }

    /** @return array<int, Course> */
    private function lockActiveCourses(array $courseIds): array
    {
        $courseIds = array_values(array_unique(array_map('intval', $courseIds)));
        sort($courseIds, SORT_NUMERIC);

        $this->courseAssessmentLockService->lockCourses($courseIds);

        // lockCourses includes soft-deleted rows in its lock set. Re-read with
        // the normal active scope so those parents remain ineligible here.
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

    private function discoverActiveSectionCourseId(int $courseSectionId): int
    {
        $courseId = DB::table('course_sections')
            ->where('id', $courseSectionId)
            ->whereNull('deleted_at')
            ->value('course_id');

        if ($courseId === null) {
            abort(404, 'Course section not found.');
        }

        return (int) $courseId;
    }

    /**
     * Direct section references retain their foreign keys after soft delete,
     * so query the tables directly instead of using soft-delete scopes.
     */
    private function sectionHasDependentRecords(int $courseSectionId): bool
    {
        foreach ([
            'lessons',
            'assignments',
            'quizzes',
            'live_classes',
        ] as $table) {
            if (
                DB::table($table)
                    ->where('course_section_id', $courseSectionId)
                    ->exists()
            ) {
                return true;
            }
        }

        return false;
    }
}

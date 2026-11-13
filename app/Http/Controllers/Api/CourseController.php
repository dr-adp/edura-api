<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Models\Course;
use App\Models\InstitutionUser;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CourseAssessmentLockService;
use App\Services\SubscriptionService;
use App\Services\UsageStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CourseController extends BaseApiController
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly UsageStatisticsService $usageStatisticsService,
        private readonly CourseAssessmentLockService $courseAssessmentLockService
    ) {}

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = Course::with([
            'institution',
            'department',
            'batch',
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

            $teacherProfile = $user->teacherProfile;

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
        | Super Admin
        |--------------------------------------------------------------------------
        */ elseif (!$user->hasRole('super-admin')) {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        $courses = $query
            ->latest()
            ->paginate(10);

        return $this->successResponse(
            $courses,
            'Courses fetched successfully.'
        );
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validated();

        /*
        |--------------------------------------------------------------------------
        | Institution Admin Restrictions
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

            /*
             * Do not silently switch the requested institution.
             * An institution admin may only create courses
             * for their own institution.
             */
            if (
                isset($validated['institution_id']) &&
                (int) $validated['institution_id'] !==
                (int) $institutionUser->institution_id
            ) {
                abort(
                    403,
                    'Unauthorized institution access.'
                );
            }

            $validated['institution_id'] =
                $institutionUser->institution_id;
        }

        /*
        |--------------------------------------------------------------------------
        | Teacher Restrictions
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

            $validated['teacher_profile_id'] =
                $teacherProfile->id;

            $validated['institution_id'] =
                $teacherProfile->institution_id;
        }

        /*
        |--------------------------------------------------------------------------
        | Authorization Check
        |--------------------------------------------------------------------------
        */
        $this->authorize('create', Course::class);

        /*
        |--------------------------------------------------------------------------
        | Course Status
        |--------------------------------------------------------------------------
        |
        | The database default is draft, so explicitly resolving the
        | effective status here keeps subscription-limit logic clear.
        |
        */
        $status = $validated['status'] ?? 'draft';

        /*
        |--------------------------------------------------------------------------
        | Subscription Requirement
        |--------------------------------------------------------------------------
        |
        | Archived courses are not billable and therefore do not require
        | a current subscription.
        |
        | Draft and published courses consume course capacity and require
        | a current subscription.
        |
        */
        $subscription = null;

        if (in_array($status, ['draft', 'published'], true)) {

            $subscription = $this->subscriptionService
                ->currentForInstitution(
                    (int) $validated['institution_id']
                );

            if ($subscription === null) {
                throw new DomainException(
                    'No active subscription found for this institution.'
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Slug Generation
        |--------------------------------------------------------------------------
        */
        $validated['slug'] =
            Str::slug($validated['title'])
            . '-' . time();

        /*
        |--------------------------------------------------------------------------
        | Course Creation + Usage Accounting
        |--------------------------------------------------------------------------
        |
        | The usage increment and course creation are part of the same
        | transaction. If either operation fails, both are rolled back.
        |
        */
        $course = DB::transaction(function () use (
            $validated,
            $status
        ) {
            if (in_array($status, ['draft', 'published'], true)) {

                $this->usageStatisticsService->increment(
                    institution: (int) $validated['institution_id'],
                    metric: 'courses',
                    amount: 1
                );
            } else {
                /*
         * Archived courses are not billable.
         *
         * If the institution has a current subscription,
         * maintain a zero usage record for the course metric.
         *
         * If there is no subscription, do not create a
         * usage record at all.
         */
                $subscription = $this->subscriptionService
                    ->currentForInstitution(
                        (int) $validated['institution_id']
                    );

                if ($subscription !== null) {
                    $this->usageStatisticsService->ensure(
                        institution: (int) $validated['institution_id'],
                        metric: 'courses'
                    );
                }
            }

            return Course::create($validated);
        });

        return $this->successResponse(
            $course->load([
                'institution',
                'department',
                'batch',
                'teacherProfile.user'
            ]),
            'Course created successfully.',
            201
        );
    }

    public function show(Course $course): JsonResponse
    {
        $this->authorize('view', $course);

        return $this->successResponse(
            $course->load([
                'institution',
                'department',
                'batch',
                'teacherProfile.user'
            ]),
            'Course fetched successfully.'
        );
    }

    public function update(
        UpdateCourseRequest $request,
        Course $course
    ): JsonResponse {
        /** @var User $user */
        $user = Auth::user();
        $validated = $request->validated();
        $courseId = (int) $course->getKey();
        $subscriptionMissing = false;

        $course = $this->courseAssessmentLockService->transactionWithLockSetRetries(
            function () use (
                $courseId,
                $validated,
                $user,
                &$subscriptionMissing
            ): Course {
                $subscriptionMissing = false;

                // All state-based checks run only after the course gate is held.
                $lockedCourse = $this->courseAssessmentLockService->lockCourse(
                    $courseId,
                    withTrashed: false
                );
                $this->authorize('update', $lockedCourse);

                if ($user->hasRole('institution-admin')) {
                    $institutionUser = InstitutionUser::where(
                        'user_id',
                        $user->id
                    )->first();

                    if (!$institutionUser) {
                        abort(403, 'Institution profile not found.');
                    }

                    $validated['institution_id'] = $institutionUser->institution_id;
                }

                if ($user->hasRole('teacher')) {
                    $teacherProfile = TeacherProfile::where(
                        'user_id',
                        $user->id
                    )->first();

                    if (!$teacherProfile) {
                        abort(403, 'Teacher profile not found.');
                    }

                    // Teachers retain their current ownership and institution boundary.
                    $validated['teacher_profile_id'] = $teacherProfile->id;
                    $validated['institution_id'] = $teacherProfile->institution_id;
                }

                $targetInstitutionId = array_key_exists('institution_id', $validated)
                    ? $this->nullableId($validated['institution_id'])
                    : $this->nullableId($lockedCourse->institution_id);
                $targetTeacherProfileId = array_key_exists('teacher_profile_id', $validated)
                    ? $this->nullableId($validated['teacher_profile_id'])
                    : $this->nullableId($lockedCourse->teacher_profile_id);

                $this->authorizeCourseAccess(
                    institutionId: $targetInstitutionId,
                    teacherProfileId: $targetTeacherProfileId
                );

                $targetDepartmentId = array_key_exists('department_id', $validated)
                    ? $this->nullableId($validated['department_id'])
                    : $this->nullableId($lockedCourse->department_id);
                $targetBatchId = array_key_exists('batch_id', $validated)
                    ? $this->nullableId($validated['batch_id'])
                    : $this->nullableId($lockedCourse->batch_id);

                $relationshipValues = [
                    'institution_id' => $targetInstitutionId,
                    'department_id' => $targetDepartmentId,
                    'batch_id' => $targetBatchId,
                    'teacher_profile_id' => $targetTeacherProfileId,
                ];
                $relationshipChanged = $relationshipValues !== [
                    'institution_id' => $this->nullableId($lockedCourse->institution_id),
                    'department_id' => $this->nullableId($lockedCourse->department_id),
                    'batch_id' => $this->nullableId($lockedCourse->batch_id),
                    'teacher_profile_id' => $this->nullableId($lockedCourse->teacher_profile_id),
                ];

                if ($relationshipChanged) {
                    $this->validateCourseRelationships($relationshipValues);
                }

                $oldInstitutionId = $this->nullableId($lockedCourse->institution_id);
                $institutionChanged = $oldInstitutionId !== $targetInstitutionId;

                if (
                    $institutionChanged &&
                    $this->courseHasDependentRecords($courseId)
                ) {
                    throw ValidationException::withMessages([
                        'institution_id' => 'A course institution cannot be changed after course content, enrollments, assessments, gradebooks, or certificates exist.',
                    ]);
                }

                $oldStatus = $lockedCourse->status;
                $newStatus = $validated['status'] ?? $oldStatus;
                $wasBillable = in_array($oldStatus, ['draft', 'published'], true);
                $willBeBillable = in_array($newStatus, ['draft', 'published'], true);

                $subscriptionRequired = $willBeBillable &&
                    (!$wasBillable || $institutionChanged);

                if ($subscriptionRequired) {
                    // Keep the existing zero-usage record behavior for archived-course reactivation.
                    if (!$wasBillable && $targetInstitutionId !== null) {
                        $this->usageStatisticsService->ensure(
                            institution: $targetInstitutionId,
                            metric: 'courses'
                        );
                    }

                    $subscription = $targetInstitutionId === null
                        ? null
                        : $this->subscriptionService->currentForInstitution(
                            $targetInstitutionId
                        );

                    if ($subscription === null) {
                        // Return normally so an ensure() above commits as before; throw after the transaction.
                        $subscriptionMissing = true;

                        return $lockedCourse;
                    }
                }

                if (isset($validated['title'])) {
                    $validated['slug'] = Str::slug($validated['title']) . '-' . time();
                }

                // Lock usage rows in institution ID order if a course changes institution.
                $usageAdjustments = [];
                if ($wasBillable && ($institutionChanged || !$willBeBillable) && $oldInstitutionId !== null) {
                    $usageAdjustments[$oldInstitutionId] = ($usageAdjustments[$oldInstitutionId] ?? 0) - 1;
                }
                if ($willBeBillable && ($institutionChanged || !$wasBillable) && $targetInstitutionId !== null) {
                    $usageAdjustments[$targetInstitutionId] = ($usageAdjustments[$targetInstitutionId] ?? 0) + 1;
                }
                ksort($usageAdjustments, SORT_NUMERIC);

                foreach ($usageAdjustments as $institutionId => $amount) {
                    if ($amount > 0) {
                        $this->usageStatisticsService->increment(
                            institution: (int) $institutionId,
                            metric: 'courses',
                            amount: $amount
                        );
                    } elseif ($amount < 0) {
                        $this->usageStatisticsService->decrement(
                            institution: (int) $institutionId,
                            metric: 'courses',
                            amount: abs($amount)
                        );
                    }
                }

                $lockedCourse->update($validated);

                return $lockedCourse->fresh();
            }
        );

        if ($subscriptionMissing) {
            throw new DomainException(
                'No active subscription found for this institution.'
            );
        }

        return $this->successResponse(
            $course->load([
                'institution',
                'department',
                'batch',
                'teacherProfile.user'
            ]),
            'Course updated successfully.'
        );
    }

    public function destroy(Course $course): JsonResponse
    {
        $courseId = (int) $course->getKey();

        $this->courseAssessmentLockService->transactionWithLockSetRetries(
            function () use ($courseId): void {
                $lockedCourse = $this->courseAssessmentLockService->lockCourse(
                    $courseId,
                    withTrashed: false
                );
                $this->authorize('delete', $lockedCourse);

                // Soft deletion preserves the course and its related records.
                $lockedCourse->delete();
            }
        );

        return $this->successResponse(
            null,
            'Course deleted successfully.'
        );
    }

    private function authorizeCourseAccess(
        ?Course $course = null,
        ?int $institutionId = null,
        ?int $teacherProfileId = null
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

            $targetInstitutionId =
                $course?->institution_id
                ?? $institutionId;

            if (
                !$targetInstitutionId ||
                (int) $targetInstitutionId !==
                (int) $institutionUser->institution_id
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

            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();

            if (!$teacherProfile) {
                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            if ($course) {

                if (
                    (int) $course->teacher_profile_id !==
                    (int) $teacherProfile->id
                ) {
                    abort(
                        403,
                        'Unauthorized course access.'
                    );
                }

                return;
            }

            if (
                $teacherProfileId &&
                (int) $teacherProfileId !==
                (int) $teacherProfile->id
            ) {
                abort(
                    403,
                    'You can only manage your own courses.'
                );
            }

            return;
        }

        abort(
            403,
            'Unauthorized role.'
        );
    }

    /**
     * Validate the effective course relationships after the course row is locked.
     * Department, batch, and teacher profile IDs are institution-scoped in the
     * existing schema, so an update must keep those links within the course tenant.
     * A batch with a department must also match an explicitly assigned course department.
     *
     * @param  array{institution_id:?int, department_id:?int, batch_id:?int, teacher_profile_id:?int}  $relationships
     */
    private function validateCourseRelationships(array $relationships): void
    {
        $errors = [];
        $institutionId = $relationships['institution_id'];
        $departmentId = $relationships['department_id'];
        $batchId = $relationships['batch_id'];
        $teacherProfileId = $relationships['teacher_profile_id'];

        if (
            $institutionId !== null &&
            !DB::table('institutions')->where('id', $institutionId)->exists()
        ) {
            $errors['institution_id'] = 'The selected institution does not exist.';
        }

        if ($departmentId !== null) {
            $department = DB::table('departments')
                ->where('id', $departmentId)
                ->first(['institution_id']);

            if (
                !$department ||
                $institutionId === null ||
                (int) $department->institution_id !== $institutionId
            ) {
                $errors['department_id'] = 'The selected department must belong to the course institution.';
            }
        }

        if ($batchId !== null) {
            $batch = DB::table('batches')
                ->where('id', $batchId)
                ->first(['institution_id', 'department_id']);

            if (
                !$batch ||
                $institutionId === null ||
                (int) $batch->institution_id !== $institutionId
            ) {
                $errors['batch_id'] = 'The selected batch must belong to the course institution.';
            } elseif (
                $departmentId !== null &&
                $batch->department_id !== null &&
                (int) $batch->department_id !== $departmentId
            ) {
                $errors['batch_id'] = 'The selected batch must belong to the selected course department.';
            }
        }

        if ($teacherProfileId !== null) {
            $teacher = TeacherProfile::query()
                ->whereKey($teacherProfileId)
                ->first(['institution_id']);

            if (
                !$teacher ||
                $institutionId === null ||
                (int) $teacher->institution_id !== $institutionId
            ) {
                $errors['teacher_profile_id'] = 'The selected teacher must belong to the course institution.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Institution reassignment changes the tenant boundary for every related
     * course record. Include soft-deleted rows by querying the tables directly;
     * no existing content or academic history is migrated by this endpoint.
     */
    private function courseHasDependentRecords(int $courseId): bool
    {
        foreach ([
            'course_sections',
            'lessons',
            'course_enrollments',
            'live_classes',
            'attendance_records',
            'assignments',
            'question_banks',
            'quizzes',
            'gradebooks',
            'certificates',
        ] as $table) {
            if (DB::table($table)->where('course_id', $courseId)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

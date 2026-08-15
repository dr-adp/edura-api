<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Models\Course;
use App\Models\InstitutionUser;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Services\UsageStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CourseController extends BaseApiController
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly UsageStatisticsService $usageStatisticsService
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
        /*
    |--------------------------------------------------------------------------
    | Existing Course Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorize('update', $course);

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

            /*
         * Prevent ownership transfer.
         */
            $validated['teacher_profile_id'] =
                $teacherProfile->id;

            /*
         * Prevent institution switching.
         */
            $validated['institution_id'] =
                $teacherProfile->institution_id;
        }

        /*
    |--------------------------------------------------------------------------
    | Re-Authorization Of Target Data
    |--------------------------------------------------------------------------
    */
        $this->authorizeCourseAccess(
            institutionId: $validated['institution_id']
                ?? $course->institution_id,

            teacherProfileId: $validated['teacher_profile_id']
                ?? $course->teacher_profile_id
        );

        /*
    |--------------------------------------------------------------------------
    | Course Status Lifecycle
    |--------------------------------------------------------------------------
    |
    | Billable statuses:
    | - draft
    | - published
    |
    | Non-billable status:
    | - archived
    |
    */
        $oldStatus = $course->status;
        $newStatus = $validated['status'] ?? $oldStatus;

        $wasBillable = in_array(
            $oldStatus,
            ['draft', 'published'],
            true
        );

        $willBeBillable = in_array(
            $newStatus,
            ['draft', 'published'],
            true
        );

        $statusChanged = $oldStatus !== $newStatus;

        /*
    |--------------------------------------------------------------------------
    | Subscription Requirement
    |--------------------------------------------------------------------------
    |
    | Only archived -> draft/published requires a new subscription
    | capacity check.
    |
    */
        if (
            $statusChanged &&
            !$wasBillable &&
            $willBeBillable
        ) {
            /*
     * Maintain a zero usage record before attempting
     * to restore an archived course.
     *
     * This also gives us a consistent usage state when
     * the transition is rejected because there is no
     * active subscription.
     */
            $this->usageStatisticsService->ensure(
                institution: (int) $course->institution_id,
                metric: 'courses'
            );

            $subscription = $this->subscriptionService
                ->currentForInstitution(
                    (int) $course->institution_id
                );

            if ($subscription === null) {
                throw new DomainException(
                    'No active subscription found for this institution.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Slug Update
    |--------------------------------------------------------------------------
    */
        if (isset($validated['title'])) {

            $validated['slug'] =
                Str::slug($validated['title'])
                . '-' . time();
        }

        /*
    |--------------------------------------------------------------------------
    | Course Update + Usage Accounting
    |--------------------------------------------------------------------------
    |
    | Usage changes and course update happen inside one transaction.
    |
    */
        $course = DB::transaction(function () use (
            $course,
            $validated,
            $statusChanged,
            $wasBillable,
            $willBeBillable
        ) {
            /*
         * Billable -> Archived
         *
         * draft -> archived
         * published -> archived
         */
            if (
                $statusChanged &&
                $wasBillable &&
                !$willBeBillable
            ) {
                $this->usageStatisticsService->decrement(
                    institution: (int) $course->institution_id,
                    metric: 'courses',
                    amount: 1
                );
            }

            /*
         * Archived -> Billable
         *
         * archived -> draft
         * archived -> published
         */
            if (
                $statusChanged &&
                !$wasBillable &&
                $willBeBillable
            ) {
                $this->usageStatisticsService->increment(
                    institution: (int) $course->institution_id,
                    metric: 'courses',
                    amount: 1
                );
            }

            $course->update($validated);

            return $course->fresh();
        });

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
        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        */
        $this->authorize('delete', $course);

        $course->delete();

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

            $teacherProfile = $user->teacherProfile;

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
}

<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTeacherProfileRequest;
use App\Http\Requests\UpdateTeacherProfileRequest;
use App\Models\TeacherProfile;
use App\Services\SubscriptionService;
use App\Services\UsageStatisticsService;
use App\Support\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherProfileController extends Controller
{
    public function __construct(
        private readonly InstitutionAccess $institutionAccess,
        private readonly SubscriptionService $subscriptionService,
        private readonly UsageStatisticsService $usageStatisticsService
    ) {}

    public function index(): JsonResponse
    {
        $teachers = TeacherProfile::with([
            'institution',
            'user.role',
            'department',
        ])
            ->latest()
            ->paginate(10);

        return response()->json([
            'message' => 'Teacher profiles fetched successfully.',
            'data' => $teachers,
        ]);
    }

    public function store(StoreTeacherProfileRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $institutionId = $this->resolveInstitutionId(
            $request,
            (int) $validated['institution_id']
        );

        $subscription = $this->subscriptionService
            ->currentForInstitution($institutionId);

        if ($subscription === null) {
            throw new DomainException(
                'No active subscription found for this institution.'
            );
        }

        $teacher = DB::transaction(function () use (
            $validated,
            $institutionId
        ) {
            /*
             * Only active teachers consume subscription capacity.
             *
             * The UsageStatisticsService performs the authoritative
             * atomic limit check and usage increment.
             */
            if (($validated['status'] ?? 'active') === 'active') {
                $this->usageStatisticsService->increment(
                    institution: $institutionId,
                    metric: 'teachers',
                    amount: 1
                );
            }

            $validated['institution_id'] = $institutionId;

            return TeacherProfile::create($validated);
        });

        return response()->json([
            'message' => 'Teacher profile created successfully.',
            'data' => $teacher->load([
                'institution',
                'user.role',
                'department',
            ]),
        ], 201);
    }

    public function show(TeacherProfile $teacherProfile): JsonResponse
    {
        return response()->json([
            'message' => 'Teacher profile fetched successfully.',
            'data' => $teacherProfile->load([
                'institution',
                'user.role',
                'department',
            ]),
        ]);
    }

    public function update(
        UpdateTeacherProfileRequest $request,
        TeacherProfile $teacherProfile
    ): JsonResponse {
        $validated = $request->validated();

        $teacherProfile->update($validated);

        return response()->json([
            'message' => 'Teacher profile updated successfully.',
            'data' => $teacherProfile->load([
                'institution',
                'user.role',
                'department',
            ]),
        ]);
    }

    public function destroy(TeacherProfile $teacherProfile): JsonResponse
    {
        $teacherProfile->delete();

        return response()->json([
            'message' => 'Teacher profile deleted successfully.',
        ]);
    }

    private function resolveInstitutionId(
        Request $request,
        int $requestedInstitutionId
    ): int {
        $user = $request->user();

        if ($user->hasRole('super-admin')) {
            return $requestedInstitutionId;
        }

        $userInstitutionId = $this->institutionAccess
            ->institutionIdFor($user);

        if ($userInstitutionId === null) {
            throw new DomainException(
                'No active institution profile found.'
            );
        }

        if ($userInstitutionId !== $requestedInstitutionId) {
            throw new DomainException(
                'You cannot create a teacher for another institution.'
            );
        }

        return $userInstitutionId;
    }
}

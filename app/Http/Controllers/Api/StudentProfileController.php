<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStudentProfileRequest;
use App\Http\Requests\UpdateStudentProfileRequest;
use App\Models\StudentProfile;
use App\Services\SubscriptionService;
use App\Services\UsageStatisticsService;
use App\Support\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentProfileController extends Controller
{
    public function __construct(
        private readonly InstitutionAccess $institutionAccess,
        private readonly SubscriptionService $subscriptionService,
        private readonly UsageStatisticsService $usageStatisticsService
    ) {}

    public function index(): JsonResponse
    {
        $students = StudentProfile::with([
            'institution',
            'user.role',
            'department',
            'batch'
        ])
            ->latest()
            ->paginate(10);

        return response()->json([
            'message' => 'Student profiles fetched successfully.',
            'data' => $students,
        ]);
    }

    public function store(StoreStudentProfileRequest $request): JsonResponse
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

        $student = DB::transaction(function () use (
            $validated,
            $institutionId
        ) {
            /*
             * increment() is the authoritative atomic usage-limit check.
             *
             * If the student creation fails after this call,
             * the outer transaction rolls the usage increment back.
             */
            $this->usageStatisticsService->increment(
                institution: $institutionId,
                metric: 'students',
                amount: 1
            );

            $validated['institution_id'] = $institutionId;

            return StudentProfile::create($validated);
        });

        return response()->json([
            'message' => 'Student profile created successfully.',
            'data' => $student->load([
                'institution',
                'user.role',
                'department',
                'batch'
            ]),
        ], 201);
    }

    public function show(StudentProfile $studentProfile): JsonResponse
    {
        return response()->json([
            'message' => 'Student profile fetched successfully.',
            'data' => $studentProfile->load([
                'institution',
                'user.role',
                'department',
                'batch'
            ]),
        ]);
    }

    public function update(
        UpdateStudentProfileRequest $request,
        StudentProfile $studentProfile
    ): JsonResponse {
        $validated = $request->validated();

        $studentProfile->update($validated);

        return response()->json([
            'message' => 'Student profile updated successfully.',
            'data' => $studentProfile->load([
                'institution',
                'user.role',
                'department',
                'batch'
            ]),
        ]);
    }

    public function destroy(StudentProfile $studentProfile): JsonResponse
    {
        $studentProfile->delete();

        return response()->json([
            'message' => 'Student profile deleted successfully.',
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
                'You cannot create a student for another institution.'
            );
        }

        return $userInstitutionId;
    }
}

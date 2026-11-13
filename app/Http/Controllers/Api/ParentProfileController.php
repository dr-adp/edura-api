<?php

namespace App\Http\Controllers\Api;

use App\Models\InstitutionUser;
use App\Models\ParentProfile;
use App\Models\User;
use App\Services\ParentStudentAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreParentProfileRequest;
use App\Http\Requests\UpdateParentProfileRequest;

class ParentProfileController extends Controller
{
    public function __construct(
        private ParentStudentAccessService $parentStudentAccessService
    ) {
    }

    public function index(): JsonResponse
    {
        $query = ParentProfile::with([
            'institution',
            'user.role',
            'studentProfile.user'
        ]);

        $this->scopeToAuthorizedInstitutions($query);

        $parents = $query
            ->latest()
            ->paginate(10);

        $parents->getCollection()->each(function (ParentProfile $parentProfile): void {
            $this->parentStudentAccessService
                ->hideInconsistentStudent($parentProfile);
        });

        return response()->json([
            'message' => 'Parent profiles fetched successfully.',
            'data' => $parents,
        ]);
    }

    public function store(StoreParentProfileRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $this->authorizeInstitution((int) $validated['institution_id']);

        $parent = ParentProfile::create($validated);
        $parent->load([
            'institution',
            'user.role',
            'studentProfile.user'
        ]);
        $this->parentStudentAccessService->hideInconsistentStudent($parent);

        return response()->json([
            'message' => 'Parent profile created successfully.',
            'data' => $parent,
        ], 201);
    }

    public function show(ParentProfile $parentProfile): JsonResponse
    {
        $this->authorizeInstitution((int) $parentProfile->institution_id);
        $parentProfile->load([
            'institution',
            'user.role',
            'studentProfile.user'
        ]);
        $this->parentStudentAccessService->hideInconsistentStudent($parentProfile);

        return response()->json([
            'message' => 'Parent profile fetched successfully.',
            'data' => $parentProfile,
        ]);
    }

    public function update(UpdateParentProfileRequest $request, ParentProfile $parentProfile): JsonResponse
    {
        $this->authorizeInstitution((int) $parentProfile->institution_id);

        $validated = $request->validated();
        $this->authorizeInstitution(
            (int) ($validated['institution_id'] ?? $parentProfile->institution_id)
        );

        $parentProfile->update($validated);
        $parentProfile->load([
            'institution',
            'user.role',
            'studentProfile.user'
        ]);
        $this->parentStudentAccessService->hideInconsistentStudent($parentProfile);

        return response()->json([
            'message' => 'Parent profile updated successfully.',
            'data' => $parentProfile,
        ]);
    }

    public function destroy(ParentProfile $parentProfile): JsonResponse
    {
        $this->authorizeInstitution((int) $parentProfile->institution_id);

        $parentProfile->delete();

        return response()->json([
            'message' => 'Parent profile deleted successfully.',
        ]);
    }

    private function scopeToAuthorizedInstitutions(Builder $query): void
    {
        $institutionIds = $this->authorizedInstitutionIds();

        if ($institutionIds !== null) {
            $query->whereIn('institution_id', $institutionIds);
        }
    }

    private function authorizeInstitution(int $institutionId): void
    {
        $institutionIds = $this->authorizedInstitutionIds();

        if (
            $institutionIds !== null &&
            ! in_array($institutionId, $institutionIds, true)
        ) {
            abort(403, 'Unauthorized institution access.');
        }
    }

    /** @return list<int>|null Null indicates an unrestricted super-admin. */
    private function authorizedInstitutionIds(): ?array
    {
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->hasRole('super-admin')) {
            return null;
        }

        if (! $user->hasRole('institution-admin')) {
            abort(403, 'Unauthorized role.');
        }

        $institutionIds = InstitutionUser::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('institution_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($institutionIds === []) {
            abort(403, 'Unauthorized institution access.');
        }

        return $institutionIds;
    }
}

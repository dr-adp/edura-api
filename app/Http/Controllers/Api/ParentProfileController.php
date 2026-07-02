<?php

namespace App\Http\Controllers\Api;

use App\Models\ParentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreParentProfileRequest;
use App\Http\Requests\UpdateParentProfileRequest;

class ParentProfileController extends Controller
{
    public function index(): JsonResponse
    {
        $parents = ParentProfile::with([
            'institution',
            'user.role',
            'studentProfile.user'
        ])
            ->latest()
            ->paginate(10);

        return response()->json([
            'message' => 'Parent profiles fetched successfully.',
            'data' => $parents,
        ]);
    }

    public function store(StoreParentProfileRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $parent = ParentProfile::create($validated);

        return response()->json([
            'message' => 'Parent profile created successfully.',
            'data' => $parent->load([
                'institution',
                'user.role',
                'studentProfile.user'
            ]),
        ], 201);
    }

    public function show(ParentProfile $parentProfile): JsonResponse
    {
        return response()->json([
            'message' => 'Parent profile fetched successfully.',
            'data' => $parentProfile->load([
                'institution',
                'user.role',
                'studentProfile.user'
            ]),
        ]);
    }

    public function update(UpdateParentProfileRequest $request, ParentProfile $parentProfile): JsonResponse
    {
        $validated = $request->validated();

        $parentProfile->update($validated);

        return response()->json([
            'message' => 'Parent profile updated successfully.',
            'data' => $parentProfile->load([
                'institution',
                'user.role',
                'studentProfile.user'
            ]),
        ]);
    }

    public function destroy(ParentProfile $parentProfile): JsonResponse
    {
        $parentProfile->delete();

        return response()->json([
            'message' => 'Parent profile deleted successfully.',
        ]);
    }
}

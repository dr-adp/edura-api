<?php

namespace App\Http\Controllers\Api;

use App\Models\InstitutionUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInstitutionUserRequest;
use App\Http\Requests\UpdateInstitutionUserRequest;

class InstitutionUserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = InstitutionUser::with(['institution', 'user.role'])
            ->latest()
            ->paginate(10);

        return response()->json([
            'message' => 'Institution users fetched successfully.',
            'data' => $users,
        ]);
    }

    public function store(StoreInstitutionUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $institutionUser = InstitutionUser::create($validated);

        return response()->json([
            'message' => 'User assigned to institution successfully.',
            'data' => $institutionUser->load(['institution', 'user.role']),
        ], 201);
    }

    public function show(InstitutionUser $institutionUser): JsonResponse
    {
        return response()->json([
            'message' => 'Institution user fetched successfully.',
            'data' => $institutionUser->load(['institution', 'user.role']),
        ]);
    }

    public function update(UpdateInstitutionUserRequest $request, InstitutionUser $institutionUser): JsonResponse
    {
        $validated = $request->validated();

        $institutionUser->update($validated);

        return response()->json([
            'message' => 'Institution user updated successfully.',
            'data' => $institutionUser->load(['institution', 'user.role']),
        ]);
    }

    public function destroy(InstitutionUser $institutionUser): JsonResponse
    {
        $institutionUser->delete();

        return response()->json([
            'message' => 'Institution user removed successfully.',
        ]);
    }
}

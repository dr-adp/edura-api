<?php

namespace App\Http\Controllers\Api;

use App\Models\InstitutionUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['required', 'exists:institutions,id'],
            'user_id' => [
                'required',
                'exists:users,id',
                Rule::unique('institution_users', 'user_id')
                    ->where('institution_id', $request->institution_id),
            ],
            'role_in_institution' => ['required', 'in:owner,admin,teacher,student,parent'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

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

    public function update(Request $request, InstitutionUser $institutionUser): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['sometimes', 'required', 'exists:institutions,id'],
            'user_id' => [
                'sometimes',
                'required',
                'exists:users,id',
                Rule::unique('institution_users', 'user_id')
                    ->where('institution_id', $request->institution_id ?? $institutionUser->institution_id)
                    ->ignore($institutionUser->id),
            ],
            'role_in_institution' => ['sometimes', 'required', 'in:owner,admin,teacher,student,parent'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

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

<?php

namespace App\Http\Controllers\Api;

use App\Models\ParentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['required', 'exists:institutions,id'],

            'user_id' => [
                'required',
                'exists:users,id',
            ],

            'student_profile_id' => [
                'required',
                'exists:student_profiles,id',
            ],

            'relationship' => ['nullable', 'string', 'max:100'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

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

    public function update(Request $request, ParentProfile $parentProfile): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['sometimes', 'required', 'exists:institutions,id'],
            'user_id' => ['sometimes', 'required', 'exists:users,id'],
            'student_profile_id' => ['sometimes', 'required', 'exists:student_profiles,id'],
            'relationship' => ['nullable', 'string', 'max:100'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

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

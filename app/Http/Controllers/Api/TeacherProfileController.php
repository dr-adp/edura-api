<?php

namespace App\Http\Controllers\Api;

use App\Models\TeacherProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTeacherProfileRequest;
use App\Http\Requests\UpdateTeacherProfileRequest;

class TeacherProfileController extends Controller
{
    public function index(): JsonResponse
    {
        $teachers = TeacherProfile::with(['institution', 'user.role', 'department'])
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

        $teacher = TeacherProfile::create($validated);

        return response()->json([
            'message' => 'Teacher profile created successfully.',
            'data' => $teacher->load(['institution', 'user.role', 'department']),
        ], 201);
    }

    public function show(TeacherProfile $teacherProfile): JsonResponse
    {
        return response()->json([
            'message' => 'Teacher profile fetched successfully.',
            'data' => $teacherProfile->load(['institution', 'user.role', 'department']),
        ]);
    }

    public function update(UpdateTeacherProfileRequest $request, TeacherProfile $teacherProfile): JsonResponse
    {
        $validated = $request->validated();

        $teacherProfile->update($validated);

        return response()->json([
            'message' => 'Teacher profile updated successfully.',
            'data' => $teacherProfile->load(['institution', 'user.role', 'department']),
        ]);
    }

    public function destroy(TeacherProfile $teacherProfile): JsonResponse
    {
        $teacherProfile->delete();

        return response()->json([
            'message' => 'Teacher profile deleted successfully.',
        ]);
    }
}

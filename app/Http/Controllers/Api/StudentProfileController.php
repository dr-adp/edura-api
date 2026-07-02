<?php

namespace App\Http\Controllers\Api;

use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStudentProfileRequest;
use App\Http\Requests\UpdateStudentProfileRequest;

class StudentProfileController extends Controller
{
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

        $student = StudentProfile::create($validated);

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

    public function update(UpdateStudentProfileRequest $request, StudentProfile $studentProfile): JsonResponse
    {
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
}

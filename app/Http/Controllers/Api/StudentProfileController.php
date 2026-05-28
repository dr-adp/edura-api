<?php

namespace App\Http\Controllers\Api;

use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['required', 'exists:institutions,id'],
            'user_id' => [
                'required',
                'exists:users,id',
                Rule::unique('student_profiles', 'user_id')
                    ->where('institution_id', $request->institution_id),
            ],
            'department_id' => ['nullable', 'exists:departments,id'],
            'batch_id' => ['nullable', 'exists:batches,id'],
            'roll_number' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:male,female,other'],
            'phone' => ['nullable', 'string', 'max:30'],
            'parent_name' => ['nullable', 'string', 'max:255'],
            'parent_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

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

    public function update(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['sometimes', 'required', 'exists:institutions,id'],
            'user_id' => [
                'sometimes',
                'required',
                'exists:users,id',
                Rule::unique('student_profiles', 'user_id')
                    ->where('institution_id', $request->institution_id ?? $studentProfile->institution_id)
                    ->ignore($studentProfile->id),
            ],
            'department_id' => ['nullable', 'exists:departments,id'],
            'batch_id' => ['nullable', 'exists:batches,id'],
            'roll_number' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:male,female,other'],
            'phone' => ['nullable', 'string', 'max:30'],
            'parent_name' => ['nullable', 'string', 'max:255'],
            'parent_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

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

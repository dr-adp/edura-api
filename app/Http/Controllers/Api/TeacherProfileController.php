<?php

namespace App\Http\Controllers\Api;

use App\Models\TeacherProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['required', 'exists:institutions,id'],
            'user_id' => [
                'required',
                'exists:users,id',
                Rule::unique('teacher_profiles', 'user_id')
                    ->where('institution_id', $request->institution_id),
            ],
            'department_id' => ['nullable', 'exists:departments,id'],
            'employee_code' => ['nullable', 'string', 'max:100'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string'],
            'experience_years' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

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

    public function update(Request $request, TeacherProfile $teacherProfile): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['sometimes', 'required', 'exists:institutions,id'],
            'user_id' => [
                'sometimes',
                'required',
                'exists:users,id',
                Rule::unique('teacher_profiles', 'user_id')
                    ->where('institution_id', $request->institution_id ?? $teacherProfile->institution_id)
                    ->ignore($teacherProfile->id),
            ],
            'department_id' => ['nullable', 'exists:departments,id'],
            'employee_code' => ['nullable', 'string', 'max:100'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string'],
            'experience_years' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

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

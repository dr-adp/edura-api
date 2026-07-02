<?php

namespace App\Http\Controllers\Api;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        $departments = Department::with('institution')
            ->latest()
            ->paginate(10);

        return response()->json([
            'message' => 'Departments fetched successfully.',
            'data' => $departments,
        ]);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $department = Department::create($validated);

        return response()->json([
            'message' => 'Department created successfully.',
            'data' => $department->load('institution'),
        ], 201);
    }

    public function show(Department $department): JsonResponse
    {
        return response()->json([
            'message' => 'Department fetched successfully.',
            'data' => $department->load('institution'),
        ]);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $validated = $request->validated();

        $department->update($validated);

        return response()->json([
            'message' => 'Department updated successfully.',
            'data' => $department->load('institution'),
        ]);
    }

    public function destroy(Department $department): JsonResponse
    {
        $department->delete();

        return response()->json([
            'message' => 'Department deleted successfully.',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class InstitutionController extends Controller
{
    public function index(): JsonResponse
    {
        $institutions = Institution::latest()->paginate(10);

        return response()->json([
            'message' => 'Institutions fetched successfully.',
            'data' => $institutions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:institutions,code'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $institution = Institution::create($validated);

        return response()->json([
            'message' => 'Institution created successfully.',
            'data' => $institution,
        ], 201);
    }

    public function show(Institution $institution): JsonResponse
    {
        return response()->json([
            'message' => 'Institution fetched successfully.',
            'data' => $institution,
        ]);
    }

    public function update(Request $request, Institution $institution): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'unique:institutions,code,' . $institution->id],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $institution->update($validated);

        return response()->json([
            'message' => 'Institution updated successfully.',
            'data' => $institution,
        ]);
    }

    public function destroy(Institution $institution): JsonResponse
    {
        $institution->delete();

        return response()->json([
            'message' => 'Institution deleted successfully.',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInstitutionRequest;
use App\Http\Requests\UpdateInstitutionRequest;

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

    public function store(StoreInstitutionRequest $request): JsonResponse
    {
        $validated = $request->validated();

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

    public function update(UpdateInstitutionRequest $request, Institution $institution): JsonResponse
    {
        $validated = $request->validated();

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

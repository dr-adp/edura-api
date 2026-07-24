<?php

namespace App\Http\Controllers\Api;

use App\Models\Institution;
use App\Services\InstitutionService;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInstitutionRequest;
use App\Http\Requests\UpdateInstitutionRequest;

class InstitutionController extends Controller
{
    private InstitutionService $institutionService;

    public function __construct(InstitutionService $institutionService)
    {
        $this->institutionService = $institutionService;
    }
    public function index(): JsonResponse
    {
        $institutions = $this->institutionService->getAll();

        return response()->json([
            'message' => 'Institutions fetched successfully.',
            'data' => $institutions,
        ]);
    }

    public function store(StoreInstitutionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $institution = $this->institutionService->create($validated);

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

        $institution = $this->institutionService->update(
            $institution,
            $validated
        );

        return response()->json([
            'message' => 'Institution updated successfully.',
            'data' => $institution,
        ]);
    }

    public function destroy(Institution $institution): JsonResponse
    {
        $this->institutionService->delete($institution);

        return response()->json([
            'message' => 'Institution deleted successfully.',
        ]);
    }
}

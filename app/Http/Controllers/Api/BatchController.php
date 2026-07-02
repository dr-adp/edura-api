<?php

namespace App\Http\Controllers\Api;

use App\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBatchRequest;
use App\Http\Requests\UpdateBatchRequest;

class BatchController extends Controller
{
    public function index(): JsonResponse
    {
        $batches = Batch::with(['institution', 'department'])
            ->latest()
            ->paginate(10);

        return response()->json([
            'message' => 'Batches fetched successfully.',
            'data' => $batches,
        ]);
    }

    public function store(StoreBatchRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $batch = Batch::create($validated);

        return response()->json([
            'message' => 'Batch created successfully.',
            'data' => $batch->load(['institution', 'department']),
        ], 201);
    }

    public function show(Batch $batch): JsonResponse
    {
        return response()->json([
            'message' => 'Batch fetched successfully.',
            'data' => $batch->load(['institution', 'department']),
        ]);
    }

    public function update(UpdateBatchRequest $request, Batch $batch): JsonResponse
    {
        $validated = $request->validated();

        $batch->update($validated);

        return response()->json([
            'message' => 'Batch updated successfully.',
            'data' => $batch->load(['institution', 'department']),
        ]);
    }

    public function destroy(Batch $batch): JsonResponse
    {
        $batch->delete();

        return response()->json([
            'message' => 'Batch deleted successfully.',
        ]);
    }
}

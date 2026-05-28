<?php

namespace App\Http\Controllers\Api;

use App\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['required', 'exists:institutions,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('batches', 'code')
                    ->where('institution_id', $request->institution_id),
            ],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'mode' => ['nullable', 'in:offline,online,hybrid'],
            'status' => ['nullable', 'in:active,inactive,completed'],
            'description' => ['nullable', 'string'],
        ]);

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

    public function update(Request $request, Batch $batch): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['sometimes', 'required', 'exists:institutions,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('batches', 'code')
                    ->where('institution_id', $request->institution_id ?? $batch->institution_id)
                    ->ignore($batch->id),
            ],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'mode' => ['nullable', 'in:offline,online,hybrid'],
            'status' => ['nullable', 'in:active,inactive,completed'],
            'description' => ['nullable', 'string'],
        ]);

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

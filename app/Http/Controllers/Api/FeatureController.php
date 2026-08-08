<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFeatureRequest;
use App\Http\Requests\UpdateFeatureRequest;
use App\Models\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeatureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Feature::class);

        $features = Feature::query()
            ->when(
                $request->user()->hasRole('institution-admin'),
                fn ($query) => $query->active()
            )
            ->orderBy('category')
            ->orderBy('name')
            ->paginate(20);

        return response()->json([
            'message' => 'Features fetched successfully.',
            'data' => $features,
        ]);
    }

    public function store(StoreFeatureRequest $request): JsonResponse
    {
        $this->authorize('create', Feature::class);

        $feature = Feature::create($request->validated());

        return response()->json([
            'message' => 'Feature created successfully.',
            'data' => $feature,
        ], 201);
    }

    public function show(Feature $feature): JsonResponse
    {
        $this->authorize('view', $feature);

        return response()->json([
            'message' => 'Feature fetched successfully.',
            'data' => $feature->load('planFeatures.subscriptionPlan'),
        ]);
    }

    public function update(
        UpdateFeatureRequest $request,
        Feature $feature
    ): JsonResponse {
        $this->authorize('update', $feature);

        $feature->update($request->validated());

        return response()->json([
            'message' => 'Feature updated successfully.',
            'data' => $feature->fresh(),
        ]);
    }

    public function destroy(Feature $feature): JsonResponse
    {
        $this->authorize('delete', $feature);

        $feature->delete();

        return response()->json([
            'message' => 'Feature deleted successfully.',
        ]);
    }
}

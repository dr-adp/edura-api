<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlanFeatureRequest;
use App\Http\Requests\UpdatePlanFeatureRequest;
use App\Models\PlanFeature;
use App\Services\FeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanFeatureController extends Controller
{
    public function __construct(
        private readonly FeatureService $featureService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PlanFeature::class);

        $planFeatures = PlanFeature::with(['subscriptionPlan', 'feature'])
            ->when(
                $request->user()->hasRole('institution-admin'),
                fn ($query) => $query->active()->enabled()
            )
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Plan features fetched successfully.',
            'data' => $planFeatures,
        ]);
    }

    public function store(StorePlanFeatureRequest $request): JsonResponse
    {
        $this->authorize('create', PlanFeature::class);

        $planFeature = $this->featureService->assignToPlan(
            $request->validated()
        );

        return response()->json([
            'message' => 'Plan feature created successfully.',
            'data' => $planFeature,
        ], 201);
    }

    public function show(PlanFeature $planFeature): JsonResponse
    {
        $this->authorize('view', $planFeature);

        return response()->json([
            'message' => 'Plan feature fetched successfully.',
            'data' => $planFeature->load(['subscriptionPlan', 'feature']),
        ]);
    }

    public function update(
        UpdatePlanFeatureRequest $request,
        PlanFeature $planFeature
    ): JsonResponse {
        $this->authorize('update', $planFeature);

        $planFeature = $this->featureService->updatePlanFeature(
            $planFeature,
            $request->validated()
        );

        return response()->json([
            'message' => 'Plan feature updated successfully.',
            'data' => $planFeature,
        ]);
    }

    public function destroy(PlanFeature $planFeature): JsonResponse
    {
        $this->authorize('delete', $planFeature);

        $this->featureService->deletePlanFeature($planFeature);

        return response()->json([
            'message' => 'Plan feature deleted successfully.',
        ]);
    }
}

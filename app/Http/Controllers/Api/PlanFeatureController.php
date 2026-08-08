<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlanFeatureRequest;
use App\Http\Requests\UpdatePlanFeatureRequest;
use App\Models\PlanFeature;
use App\Services\FeatureService;
use App\Services\SubscriptionService;
use App\Support\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanFeatureController extends Controller
{
    public function __construct(
        private readonly FeatureService $featureService,
        private readonly InstitutionAccess $institutionAccess,
        private readonly SubscriptionService $subscriptionService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PlanFeature::class);

        $query = PlanFeature::with([
            'subscriptionPlan',
            'feature',
        ]);

        if ($request->user()->hasRole('institution-admin')) {
            $institutionId = $this->institutionAccess
                ->institutionIdFor($request->user());

            if ($institutionId === null) {
                return response()->json([
                    'message' => 'No active institution profile found.',
                    'data' => [],
                ]);
            }

            $currentPlan = $this->subscriptionService
                ->currentPlanForInstitution($institutionId);

            if ($currentPlan === null) {
                return response()->json([
                    'message' => 'No active subscription found.',
                    'data' => [],
                ]);
            }

            $query
                ->where('subscription_plan_id', $currentPlan->id)
                ->active()
                ->enabled();
        }

        $planFeatures = $query
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
            'data' => $planFeature->load([
                'subscriptionPlan',
                'feature',
            ]),
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

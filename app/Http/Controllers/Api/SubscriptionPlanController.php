<?php

namespace App\Http\Controllers\Api;

use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubscriptionPlanRequest;
use App\Http\Requests\UpdateSubscriptionPlanRequest;
use Illuminate\Http\Request;

class SubscriptionPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SubscriptionPlan::class);

        $plans = SubscriptionPlan::with('planFeatures.feature')
            ->when(
                $request->user()->hasRole('institution-admin'),
                fn ($query) => $query->active()
            )
            ->orderBy('sort_order')
            ->latest()
            ->paginate(10);

        return response()->json([
            'message' => 'Subscription plans fetched successfully.',
            'data' => $plans,
        ]);
    }

    public function store(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        $this->authorize('create', SubscriptionPlan::class);

        $validated = $request->validated();

        $plan = SubscriptionPlan::create($validated);

        return response()->json([
            'message' => 'Subscription plan created successfully.',
            'data' => $plan,
        ], 201);
    }

    public function show(SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $this->authorize('view', $subscriptionPlan);

        return response()->json([
            'message' => 'Subscription plan fetched successfully.',
            'data' => $subscriptionPlan->load('planFeatures.feature'),
        ]);
    }

    public function update(UpdateSubscriptionPlanRequest $request, SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $this->authorize('update', $subscriptionPlan);

        $validated = $request->validated();

        $subscriptionPlan->update($validated);

        return response()->json([
            'message' => 'Subscription plan updated successfully.',
            'data' => $subscriptionPlan->fresh()->load('planFeatures.feature'),
        ]);
    }

    public function destroy(SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $this->authorize('delete', $subscriptionPlan);

        $subscriptionPlan->delete();

        return response()->json([
            'message' => 'Subscription plan deleted successfully.',
        ]);
    }
}

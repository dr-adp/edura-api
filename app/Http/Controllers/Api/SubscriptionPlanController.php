<?php

namespace App\Http\Controllers\Api;

use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubscriptionPlanRequest;
use App\Http\Requests\UpdateSubscriptionPlanRequest;

class SubscriptionPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = SubscriptionPlan::latest()->paginate(10);

        return response()->json([
            'message' => 'Subscription plans fetched successfully.',
            'data' => $plans,
        ]);
    }

    public function store(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $plan = SubscriptionPlan::create($validated);

        return response()->json([
            'message' => 'Subscription plan created successfully.',
            'data' => $plan,
        ], 201);
    }

    public function show(SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        return response()->json([
            'message' => 'Subscription plan fetched successfully.',
            'data' => $subscriptionPlan,
        ]);
    }

    public function update(UpdateSubscriptionPlanRequest $request, SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $validated = $request->validated();

        $subscriptionPlan->update($validated);

        return response()->json([
            'message' => 'Subscription plan updated successfully.',
            'data' => $subscriptionPlan,
        ]);
    }

    public function destroy(SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $subscriptionPlan->delete();

        return response()->json([
            'message' => 'Subscription plan deleted successfully.',
        ]);
    }
}

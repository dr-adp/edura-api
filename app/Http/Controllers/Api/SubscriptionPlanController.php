<?php

namespace App\Http\Controllers\Api;

use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:subscription_plans,code'],
            'price' => ['required', 'numeric', 'min:0'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'max_teachers' => ['required', 'integer', 'min:1'],
            'max_students' => ['required', 'integer', 'min:1'],
            'max_courses' => ['required', 'integer', 'min:1'],
            'storage_limit_mb' => ['required', 'integer', 'min:100'],
            'allow_live_classes' => ['boolean'],
            'allow_recorded_classes' => ['boolean'],
            'allow_ai_reports' => ['boolean'],
            'allow_hand_sign_module' => ['boolean'],
            'allow_noticeboard' => ['boolean'],
            'allow_notes_upload' => ['boolean'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

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

    public function update(Request $request, SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'unique:subscription_plans,code,' . $subscriptionPlan->id],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'billing_cycle' => ['sometimes', 'required', 'in:monthly,yearly'],
            'max_teachers' => ['sometimes', 'required', 'integer', 'min:1'],
            'max_students' => ['sometimes', 'required', 'integer', 'min:1'],
            'max_courses' => ['sometimes', 'required', 'integer', 'min:1'],
            'storage_limit_mb' => ['sometimes', 'required', 'integer', 'min:100'],
            'allow_live_classes' => ['boolean'],
            'allow_recorded_classes' => ['boolean'],
            'allow_ai_reports' => ['boolean'],
            'allow_hand_sign_module' => ['boolean'],
            'allow_noticeboard' => ['boolean'],
            'allow_notes_upload' => ['boolean'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

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

<?php

namespace App\Http\Controllers\Api;

use App\Models\InstitutionSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class InstitutionSubscriptionController extends Controller
{
    public function index(): JsonResponse
    {
        $subscriptions = InstitutionSubscription::with(['institution', 'subscriptionPlan'])
            ->latest()
            ->paginate(10);

        return response()->json([
            'message' => 'Institution subscriptions fetched successfully.',
            'data' => $subscriptions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['required', 'exists:institutions,id'],
            'subscription_plan_id' => ['required', 'exists:subscription_plans,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'amount_paid' => ['required', 'numeric', 'min:0'],
            'payment_status' => ['nullable', 'in:pending,paid,failed,refunded'],
            'status' => ['nullable', 'in:active,expired,cancelled'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        InstitutionSubscription::where('institution_id', $validated['institution_id'])
            ->where('status', 'active')
            ->update(['status' => 'expired']);

        $subscription = InstitutionSubscription::create($validated);

        return response()->json([
            'message' => 'Institution subscription created successfully.',
            'data' => $subscription->load(['institution', 'subscriptionPlan']),
        ], 201);
    }

    public function show(InstitutionSubscription $institutionSubscription): JsonResponse
    {
        return response()->json([
            'message' => 'Institution subscription fetched successfully.',
            'data' => $institutionSubscription->load(['institution', 'subscriptionPlan']),
        ]);
    }

    public function update(Request $request, InstitutionSubscription $institutionSubscription): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['sometimes', 'required', 'exists:institutions,id'],
            'subscription_plan_id' => ['sometimes', 'required', 'exists:subscription_plans,id'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after:start_date'],
            'amount_paid' => ['sometimes', 'required', 'numeric', 'min:0'],
            'payment_status' => ['nullable', 'in:pending,paid,failed,refunded'],
            'status' => ['nullable', 'in:active,expired,cancelled'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $institutionSubscription->update($validated);

        return response()->json([
            'message' => 'Institution subscription updated successfully.',
            'data' => $institutionSubscription->load(['institution', 'subscriptionPlan']),
        ]);
    }

    public function destroy(InstitutionSubscription $institutionSubscription): JsonResponse
    {
        $institutionSubscription->delete();

        return response()->json([
            'message' => 'Institution subscription deleted successfully.',
        ]);
    }
}

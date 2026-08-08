<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubscriptionRequest;
use App\Http\Requests\UpdateSubscriptionRequest;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Support\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly InstitutionAccess $institutionAccess
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Subscription::class);

        $subscriptions = Subscription::with(['institution', 'subscriptionPlan'])
            ->when(
                $request->user()->hasRole('institution-admin'),
                fn ($query) => $query->where(
                    'institution_id',
                    $this->institutionAccess->institutionIdFor($request->user())
                )
            )
            ->latest()
            ->paginate(10);

        return response()->json([
            'message' => 'Subscriptions fetched successfully.',
            'data' => $subscriptions,
        ]);
    }

    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        $this->authorize('create', Subscription::class);

        try {
            $subscription = $this->subscriptionService->create(array_merge(
                $request->validated(),
                ['created_by_id' => $request->user()->id]
            ));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'message' => 'Subscription created successfully.',
            'data' => $subscription,
        ], 201);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        $this->authorize('view', $subscription);

        return response()->json([
            'message' => 'Subscription fetched successfully.',
            'data' => $subscription->load(['institution', 'subscriptionPlan']),
        ]);
    }

    public function update(
        UpdateSubscriptionRequest $request,
        Subscription $subscription
    ): JsonResponse {
        $this->authorize('update', $subscription);

        try {
            $subscription = $this->subscriptionService->update(
                $subscription,
                $request->validated()
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'message' => 'Subscription updated successfully.',
            'data' => $subscription,
        ]);
    }

    public function destroy(Subscription $subscription): JsonResponse
    {
        $this->authorize('delete', $subscription);

        $this->subscriptionService->delete($subscription);

        return response()->json([
            'message' => 'Subscription deleted successfully.',
        ]);
    }

    public function activate(Subscription $subscription): JsonResponse
    {
        $this->authorize('update', $subscription);

        return response()->json([
            'message' => 'Subscription activated successfully.',
            'data' => $this->subscriptionService->activate($subscription),
        ]);
    }

    public function suspend(Subscription $subscription): JsonResponse
    {
        $this->authorize('update', $subscription);

        try {
            $subscription = $this->subscriptionService->suspend($subscription);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'message' => 'Subscription suspended successfully.',
            'data' => $subscription,
        ]);
    }

    public function cancel(Subscription $subscription): JsonResponse
    {
        $this->authorize('update', $subscription);

        try {
            $subscription = $this->subscriptionService->cancel($subscription);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'message' => 'Subscription cancelled successfully.',
            'data' => $subscription,
        ]);
    }

    public function expire(Subscription $subscription): JsonResponse
    {
        $this->authorize('update', $subscription);

        return response()->json([
            'message' => 'Subscription expired successfully.',
            'data' => $this->subscriptionService->expire($subscription),
        ]);
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'errors' => $exception->context(),
        ], 422);
    }
}

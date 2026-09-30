<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Institution;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AICreditService $aiCreditService
    ) {}

    private function grantIncludedAICreditsIfNeeded(
        Subscription $subscription
    ): void {
        $this->aiCreditService->grantIncludedForSubscription(
            $subscription,
            [
                'created_by_id' => auth()->id(),
            ]
        );
    }

    public function create(array $data): Subscription
    {
        return DB::transaction(function () use ($data) {
            $institution = $this->lockInstitution(
                (int) $data['institution_id']
            );

            $plan = SubscriptionPlan::query()
                ->active()
                ->findOrFail($data['subscription_plan_id']);

            $status = $data['status'] ?? Subscription::STATUS_TRIAL;
            $startsAt = Carbon::parse($data['starts_at'] ?? now());
            $billingCycle = $data['billing_cycle'] ?? $plan->billing_cycle;

            $this->closeCurrentSubscriptions(
                $institution->id,
                $status
            );

            $subscription = Subscription::create(array_merge($data, [
                'uuid' => $data['uuid'] ?? (string) Str::uuid(),
                'status' => $status,
                'billing_cycle' => $billingCycle,
                'starts_at' => $startsAt,
                'trial_ends_at' => $data['trial_ends_at']
                    ?? $this->trialEndsAt($startsAt, $plan, $status),
                'current_period_starts_at' => $data['current_period_starts_at']
                    ?? $startsAt,
                'current_period_ends_at' => $data['current_period_ends_at']
                    ?? $this->periodEndsAt($startsAt, $billingCycle),
            ]));

            $subscription = $subscription->load([
                'institution',
                'subscriptionPlan',
            ]);

            $this->auditLogService->recordCustom(
                action: 'subscription_created',
                description: 'Subscription created.',
                auditable: $subscription,
                institutionId: $institution->id,
                module: 'Subscription',
                metadata: [
                    'subscription_plan_id' => $subscription->subscription_plan_id,
                    'status' => $subscription->status,
                    'billing_cycle' => $subscription->billing_cycle,
                ]
            );

            if ($subscription->status === Subscription::STATUS_ACTIVE) {
                $this->grantIncludedAICreditsIfNeeded($subscription);
            }

            return $subscription;
        });
    }

    public function update(
        Subscription $subscription,
        array $data
    ): Subscription {
        return DB::transaction(function () use ($subscription, $data) {
            $institution = $this->lockInstitution(
                (int) $subscription->institution_id
            );

            $currentStatus = $subscription->status;
            $newStatus = $data['status'] ?? $currentStatus;

            $this->ensureValidTransition(
                $currentStatus,
                $newStatus
            );

            if (
                in_array($newStatus, [
                    Subscription::STATUS_TRIAL,
                    Subscription::STATUS_ACTIVE,
                ], true)
            ) {
                $this->closeCurrentSubscriptions(
                    $institution->id,
                    $newStatus,
                    $subscription->id
                );
            }

            $oldValues = $subscription->getAttributes();

            $subscription->update($data);

            $subscription = $subscription->fresh()->load([
                'institution',
                'subscriptionPlan',
            ]);

            $this->auditLogService->recordCustom(
                action: 'subscription_updated',
                description: 'Subscription updated.',
                auditable: $subscription,
                institutionId: $institution->id,
                module: 'Subscription',
                oldValues: $oldValues,
                newValues: $subscription->getAttributes()
            );

            if (
                $currentStatus !== Subscription::STATUS_ACTIVE &&
                $subscription->status === Subscription::STATUS_ACTIVE
            ) {
                $this->grantIncludedAICreditsIfNeeded($subscription);
            }

            return $subscription;
        });
    }

    public function activate(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $institution = $this->lockInstitution(
                (int) $subscription->institution_id
            );

            $this->ensureValidTransition(
                $subscription->status,
                Subscription::STATUS_ACTIVE
            );

            $this->closeCurrentSubscriptions(
                $institution->id,
                Subscription::STATUS_ACTIVE,
                $subscription->id
            );

            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'suspended_at' => null,
                'cancelled_at' => null,
                'expires_at' => null,
                'current_period_starts_at' => $subscription->current_period_starts_at
                    ?? now(),
                'current_period_ends_at' => $subscription->current_period_ends_at
                    ?? $this->periodEndsAt(
                        now(),
                        $subscription->billing_cycle
                    ),
            ]);

            $subscription = $subscription->fresh()->load([
                'institution',
                'subscriptionPlan',
            ]);

            $this->auditLogService->recordCustom(
                action: 'subscription_activated',
                description: 'Subscription activated.',
                auditable: $subscription,
                institutionId: $institution->id,
                module: 'Subscription',
                metadata: [
                    'subscription_plan_id' => $subscription->subscription_plan_id,
                ]
            );

            $this->grantIncludedAICreditsIfNeeded($subscription);

            return $subscription;
        });
    }

    public function suspend(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $institution = $this->lockInstitution(
                (int) $subscription->institution_id
            );

            $this->ensureValidTransition(
                $subscription->status,
                Subscription::STATUS_SUSPENDED
            );

            $subscription->update([
                'status' => Subscription::STATUS_SUSPENDED,
                'suspended_at' => now(),
            ]);

            $subscription = $subscription->fresh()->load([
                'institution',
                'subscriptionPlan',
            ]);

            $this->auditLogService->recordCustom(
                action: 'subscription_suspended',
                description: 'Subscription suspended.',
                auditable: $subscription,
                institutionId: $institution->id,
                module: 'Subscription'
            );

            return $subscription;
        });
    }

    public function cancel(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $institution = $this->lockInstitution(
                (int) $subscription->institution_id
            );

            $this->ensureValidTransition(
                $subscription->status,
                Subscription::STATUS_CANCELLED
            );

            $subscription->update([
                'status' => Subscription::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'expires_at' => now(),
            ]);

            $subscription = $subscription->fresh()->load([
                'institution',
                'subscriptionPlan',
            ]);

            $this->auditLogService->recordCustom(
                action: 'subscription_cancelled',
                description: 'Subscription cancelled.',
                auditable: $subscription,
                institutionId: $institution->id,
                module: 'Subscription'
            );

            return $subscription;
        });
    }

    public function expire(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $institution = $this->lockInstitution(
                (int) $subscription->institution_id
            );

            $lockedSubscription = Subscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureValidTransition(
                $lockedSubscription->status,
                Subscription::STATUS_EXPIRED
            );

            return $this->markExpired(
                $lockedSubscription,
                $institution
            );
        });
    }

    /**
     * Expire a subscription only if it is still eligible.
     *
     * Returns null when the subscription is no longer eligible.
     */
    public function expireIfEligible(
        Subscription $subscription,
        int $gracePeriodDays
    ): ?Subscription {
        return DB::transaction(function () use (
            $subscription,
            $gracePeriodDays
        ) {
            // Follow the existing institution-first locking convention.
            $institution = $this->lockInstitution(
                (int) $subscription->institution_id
            );

            // Always use the latest database state, not the stale model
            // selected earlier by the scheduled command.
            $lockedSubscription = Subscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            $now = now();
            $gracePeriodDays = max(0, $gracePeriodDays);

            $trialEligible =
                $lockedSubscription->status === Subscription::STATUS_TRIAL
                && $lockedSubscription->trial_ends_at !== null
                && $lockedSubscription->trial_ends_at->lte($now);

            $activeEligible =
                $lockedSubscription->status === Subscription::STATUS_ACTIVE
                && $lockedSubscription->current_period_ends_at !== null
                && $lockedSubscription->current_period_ends_at->lte(
                    $now->copy()->subDays($gracePeriodDays)
                );

            if (! $trialEligible && ! $activeEligible) {
                return null;
            }

            $this->ensureValidTransition(
                $lockedSubscription->status,
                Subscription::STATUS_EXPIRED
            );

            return $this->markExpired(
                $lockedSubscription,
                $institution
            );
        });
    }

    /**
     * Persist an expiry and record its audit event.
     * The caller must already hold the institution lock.
     */
    private function markExpired(
        Subscription $subscription,
        Institution $institution
    ): Subscription {
        $subscription->update([
            'status' => Subscription::STATUS_EXPIRED,
            'expires_at' => now(),
        ]);

        $subscription = $subscription->fresh()->load([
            'institution',
            'subscriptionPlan',
        ]);

        $this->auditLogService->recordCustom(
            action: 'subscription_expired',
            description: 'Subscription expired.',
            auditable: $subscription,
            institutionId: $institution->id,
            module: 'Subscription'
        );

        return $subscription;
    }

    public function delete(Subscription $subscription): bool
    {
        return DB::transaction(function () use ($subscription) {
            $this->lockInstitution(
                (int) $subscription->institution_id
            );

            return (bool) $subscription->delete();
        });
    }

    public function currentForInstitution(
        Institution|int $institution
    ): ?Subscription {
        $institutionId = $institution instanceof Institution
            ? $institution->id
            : $institution;

        return Subscription::query()
            ->with('subscriptionPlan.planFeatures.feature')
            ->forInstitution((int) $institutionId)
            ->current()
            ->latest()
            ->first();
    }

    public function currentPlanForInstitution(
        Institution|int $institution
    ): ?SubscriptionPlan {
        $subscription = $this->currentForInstitution($institution);

        if ($subscription?->subscriptionPlan) {
            return $subscription->subscriptionPlan;
        }

        $institutionModel = $institution instanceof Institution
            ? $institution
            : Institution::find($institution);

        return $institutionModel
            ? $institutionModel->activeSubscription()
            ->with('subscriptionPlan.planFeatures.feature')
            ->first()
            ?->subscriptionPlan
            : null;
    }

    private function lockInstitution(int $institutionId): Institution
    {
        return Institution::query()
            ->whereKey($institutionId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function closeCurrentSubscriptions(
        int $institutionId,
        string $newStatus,
        ?int $exceptSubscriptionId = null
    ): void {
        if (!in_array($newStatus, [
            Subscription::STATUS_TRIAL,
            Subscription::STATUS_ACTIVE,
        ], true)) {
            return;
        }

        Subscription::query()
            ->forInstitution($institutionId)
            ->current()
            ->when(
                $exceptSubscriptionId,
                fn($query) => $query->whereKeyNot($exceptSubscriptionId)
            )
            ->update([
                'status' => Subscription::STATUS_EXPIRED,
                'expires_at' => now(),
            ]);
    }

    private function ensureValidTransition(
        string $currentStatus,
        string $newStatus
    ): void {
        if ($currentStatus === $newStatus) {
            if (in_array($currentStatus, [
                Subscription::STATUS_CANCELLED,
                Subscription::STATUS_EXPIRED,
            ], true)) {
                throw new DomainException(
                    'Finalized subscriptions cannot be changed.'
                );
            }

            return;
        }

        $allowedTransitions = [
            Subscription::STATUS_TRIAL => [
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_SUSPENDED,
                Subscription::STATUS_CANCELLED,
                Subscription::STATUS_EXPIRED,
            ],

            Subscription::STATUS_ACTIVE => [
                Subscription::STATUS_SUSPENDED,
                Subscription::STATUS_CANCELLED,
                Subscription::STATUS_EXPIRED,
            ],

            Subscription::STATUS_SUSPENDED => [
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_CANCELLED,
                Subscription::STATUS_EXPIRED,
            ],

            Subscription::STATUS_CANCELLED => [],

            Subscription::STATUS_EXPIRED => [],
        ];

        if (!in_array(
            $newStatus,
            $allowedTransitions[$currentStatus] ?? [],
            true
        )) {
            throw new DomainException(
                "Invalid subscription status transition from [{$currentStatus}] to [{$newStatus}].",
                [
                    'current_status' => $currentStatus,
                    'requested_status' => $newStatus,
                ]
            );
        }
    }

    private function trialEndsAt(
        Carbon $startsAt,
        SubscriptionPlan $plan,
        string $status
    ): ?Carbon {
        if ($status !== Subscription::STATUS_TRIAL) {
            return null;
        }

        return $plan->trial_days > 0
            ? $startsAt->copy()->addDays($plan->trial_days)
            : $startsAt->copy();
    }

    private function periodEndsAt(
        Carbon $startsAt,
        string $billingCycle
    ): Carbon {
        return $billingCycle === 'monthly'
            ? $startsAt->copy()->addMonth()
            : $startsAt->copy()->addYear();
    }
}

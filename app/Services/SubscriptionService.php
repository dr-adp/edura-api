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
        private readonly AuditLogService $auditLogService
    ) {}

    public function create(array $data): Subscription
    {
        return DB::transaction(function () use ($data) {
            $plan = SubscriptionPlan::query()
                ->active()
                ->findOrFail($data['subscription_plan_id']);

            $status = $data['status'] ?? Subscription::STATUS_TRIAL;
            $startsAt = Carbon::parse($data['starts_at'] ?? now());
            $billingCycle = $data['billing_cycle'] ?? $plan->billing_cycle;

            $this->closeCurrentSubscriptions(
                (int) $data['institution_id'],
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
                institutionId: (int) $subscription->institution_id,
                module: 'Subscription',
                metadata: [
                    'subscription_plan_id' => $subscription->subscription_plan_id,
                    'status' => $subscription->status,
                    'billing_cycle' => $subscription->billing_cycle,
                ]
            );

            return $subscription;
        });
    }

    public function update(Subscription $subscription, array $data): Subscription
    {
        return DB::transaction(function () use ($subscription, $data) {
            if (
                isset($data['status']) &&
                in_array($data['status'], [
                    Subscription::STATUS_TRIAL,
                    Subscription::STATUS_ACTIVE,
                ], true)
            ) {
                $this->closeCurrentSubscriptions(
                    (int) $subscription->institution_id,
                    $data['status'],
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
                institutionId: (int) $subscription->institution_id,
                module: 'Subscription',
                oldValues: $oldValues,
                newValues: $subscription->getAttributes()
            );

            return $subscription;
        });
    }

    public function activate(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $this->closeCurrentSubscriptions(
                (int) $subscription->institution_id,
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
                    ?? $this->periodEndsAt(now(), $subscription->billing_cycle),
            ]);

            $subscription = $subscription->fresh()->load([
                'institution',
                'subscriptionPlan',
            ]);

            $this->auditLogService->recordCustom(
                action: 'subscription_activated',
                description: 'Subscription activated.',
                auditable: $subscription,
                institutionId: (int) $subscription->institution_id,
                module: 'Subscription',
                metadata: [
                    'subscription_plan_id' => $subscription->subscription_plan_id,
                ]
            );

            return $subscription;
        });
    }

    public function suspend(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $this->ensureNotFinal($subscription);

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
                institutionId: (int) $subscription->institution_id,
                module: 'Subscription'
            );

            return $subscription;
        });
    }

    public function cancel(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $this->ensureNotFinal($subscription);

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
                institutionId: (int) $subscription->institution_id,
                module: 'Subscription'
            );

            return $subscription;
        });
    }

    public function expire(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
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
                institutionId: (int) $subscription->institution_id,
                module: 'Subscription'
            );

            return $subscription;
        });
    }

    public function delete(Subscription $subscription): bool
    {
        return DB::transaction(
            fn(): bool => (bool) $subscription->delete()
        );
    }

    public function currentForInstitution(Institution|int $institution): ?Subscription
    {
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

    private function ensureNotFinal(Subscription $subscription): void
    {
        if (in_array($subscription->status, [
            Subscription::STATUS_EXPIRED,
            Subscription::STATUS_CANCELLED,
        ], true)) {
            throw new DomainException(
                'Finalized subscriptions cannot be changed.'
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

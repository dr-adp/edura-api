<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Feature;
use App\Models\Institution;
use App\Models\PlanFeature;
use Illuminate\Support\Facades\DB;

class FeatureService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService
    ) {
    }

    public function enabled(Institution|int $institution, string $featureCode): bool
    {
        $plan = $this->subscriptionService
            ->currentPlanForInstitution($institution);

        if (!$plan) {
            return false;
        }

        return $plan->planFeatures()
            ->whereHas('feature', function ($query) use ($featureCode) {
                $query->where('code', $featureCode)
                    ->where('status', 'active');
            })
            ->where('enabled', true)
            ->where('status', 'active')
            ->exists();
    }

    public function assertEnabled(Institution|int $institution, string $featureCode): void
    {
        if (!$this->enabled($institution, $featureCode)) {
            throw new DomainException(
                "Feature [{$featureCode}] is not enabled for this institution."
            );
        }
    }

    public function assignToPlan(array $data): PlanFeature
    {
        return DB::transaction(function () use ($data) {
            Feature::query()->active()->findOrFail($data['feature_id']);

            $planFeature = PlanFeature::updateOrCreate(
                [
                    'subscription_plan_id' => $data['subscription_plan_id'],
                    'feature_id' => $data['feature_id'],
                ],
                [
                    'enabled' => $data['enabled'] ?? true,
                    'value' => $data['value'] ?? null,
                    'status' => $data['status'] ?? 'active',
                    'metadata' => $data['metadata'] ?? null,
                ]
            );

            return $planFeature->load(['subscriptionPlan', 'feature']);
        });
    }

    public function updatePlanFeature(
        PlanFeature $planFeature,
        array $data
    ): PlanFeature {
        return DB::transaction(function () use ($planFeature, $data) {
            $planFeature->update($data);

            return $planFeature->fresh()->load(['subscriptionPlan', 'feature']);
        });
    }

    public function deletePlanFeature(PlanFeature $planFeature): bool
    {
        return DB::transaction(
            fn (): bool => (bool) $planFeature->delete()
        );
    }
}

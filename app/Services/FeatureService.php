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
        private readonly SubscriptionService $subscriptionService,
        private readonly AuditLogService $auditLogService
    ) {}

    public function enabled(
        Institution|int $institution,
        string $featureCode
    ): bool {
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

    public function assertEnabled(
        Institution|int $institution,
        string $featureCode
    ): void {
        if (!$this->enabled($institution, $featureCode)) {
            throw new DomainException(
                "Feature [{$featureCode}] is not enabled for this institution."
            );
        }
    }

    public function assignToPlan(array $data): PlanFeature
    {
        return DB::transaction(function () use ($data) {
            Feature::query()
                ->active()
                ->findOrFail($data['feature_id']);

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

            $planFeature = $planFeature->load([
                'subscriptionPlan',
                'feature',
            ]);

            $this->auditLogService->recordCustom(
                action: 'plan_feature_assigned',
                description: 'Feature assigned to subscription plan.',
                auditable: $planFeature,
                module: 'Feature',
                metadata: [
                    'subscription_plan_id' => $planFeature->subscription_plan_id,
                    'feature_id' => $planFeature->feature_id,
                    'feature_code' => $planFeature->feature?->code,
                    'enabled' => $planFeature->enabled,
                ],
                useAuthenticatedUser: true
            );

            return $planFeature;
        });
    }

    public function updatePlanFeature(
        PlanFeature $planFeature,
        array $data
    ): PlanFeature {
        return DB::transaction(function () use ($planFeature, $data) {
            $oldValues = $planFeature->getAttributes();

            $planFeature->update($data);

            $planFeature = $planFeature->fresh()->load([
                'subscriptionPlan',
                'feature',
            ]);

            $this->auditLogService->recordCustom(
                action: 'plan_feature_updated',
                description: 'Subscription plan feature updated.',
                auditable: $planFeature,
                module: 'Feature',
                oldValues: $oldValues,
                newValues: $planFeature->getAttributes(),
                metadata: [
                    'subscription_plan_id' => $planFeature->subscription_plan_id,
                    'feature_id' => $planFeature->feature_id,
                    'feature_code' => $planFeature->feature?->code,
                ]
            );

            return $planFeature;
        });
    }

    public function deletePlanFeature(PlanFeature $planFeature): bool
    {
        return DB::transaction(function () use ($planFeature) {
            $oldValues = $planFeature->getAttributes();

            $this->auditLogService->recordCustom(
                action: 'plan_feature_removed',
                description: 'Feature removed from subscription plan.',
                auditable: $planFeature,
                module: 'Feature',
                oldValues: $oldValues
            );

            return (bool) $planFeature->delete();
        });
    }
}

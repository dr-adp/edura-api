<?php

namespace App\Policies;

use App\Models\PlanFeature;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\InstitutionAccess;

class PlanFeaturePolicy
{
    public function __construct(
        private readonly InstitutionAccess $institutionAccess,
        private readonly SubscriptionService $subscriptionService
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            'super-admin',
            'institution-admin',
        ]);
    }

    public function view(User $user, PlanFeature $planFeature): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        if (
            !$user->hasRole('institution-admin') ||
            $planFeature->status !== 'active'
        ) {
            return false;
        }

        $institutionId = $this->institutionAccess->institutionIdFor($user);

        if ($institutionId === null) {
            return false;
        }

        $currentPlan = $this->subscriptionService
            ->currentPlanForInstitution($institutionId);

        if ($currentPlan === null) {
            return false;
        }

        return (int) $planFeature->subscription_plan_id
            === (int) $currentPlan->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function update(User $user, PlanFeature $planFeature): bool
    {
        return $user->hasRole('super-admin');
    }

    public function delete(User $user, PlanFeature $planFeature): bool
    {
        return $user->hasRole('super-admin');
    }
}

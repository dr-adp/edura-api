<?php

namespace App\Policies;

use App\Models\SubscriptionPlan;
use App\Models\User;

class SubscriptionPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'institution-admin']);
    }

    public function view(User $user, SubscriptionPlan $subscriptionPlan): bool
    {
        return $user->hasRole('super-admin')
            || (
                $user->hasRole('institution-admin') &&
                $subscriptionPlan->status === 'active'
            );
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function update(User $user, SubscriptionPlan $subscriptionPlan): bool
    {
        return $user->hasRole('super-admin');
    }

    public function delete(User $user, SubscriptionPlan $subscriptionPlan): bool
    {
        return $user->hasRole('super-admin');
    }
}

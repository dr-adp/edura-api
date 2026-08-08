<?php

namespace App\Policies;

use App\Models\PlanFeature;
use App\Models\User;

class PlanFeaturePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'institution-admin']);
    }

    public function view(User $user, PlanFeature $planFeature): bool
    {
        return $user->hasRole('super-admin')
            || (
                $user->hasRole('institution-admin') &&
                $planFeature->status === 'active'
            );
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

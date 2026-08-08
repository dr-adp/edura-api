<?php

namespace App\Policies;

use App\Models\Feature;
use App\Models\User;

class FeaturePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'institution-admin']);
    }

    public function view(User $user, Feature $feature): bool
    {
        return $user->hasRole('super-admin')
            || (
                $user->hasRole('institution-admin') &&
                $feature->status === 'active'
            );
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function update(User $user, Feature $feature): bool
    {
        return $user->hasRole('super-admin');
    }

    public function delete(User $user, Feature $feature): bool
    {
        return $user->hasRole('super-admin');
    }
}

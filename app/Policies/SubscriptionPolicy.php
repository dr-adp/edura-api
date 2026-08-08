<?php

namespace App\Policies;

use App\Models\InstitutionUser;
use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'institution-admin']);
    }

    public function view(User $user, Subscription $subscription): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        $institutionUser = $this->activeInstitutionUser($user);

        return $institutionUser
            && (int) $institutionUser->institution_id ===
                (int) $subscription->institution_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return $user->hasRole('super-admin');
    }

    public function delete(User $user, Subscription $subscription): bool
    {
        return $user->hasRole('super-admin');
    }

    private function activeInstitutionUser(User $user): ?InstitutionUser
    {
        return InstitutionUser::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }
}

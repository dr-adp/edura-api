<?php

namespace App\Policies;

use App\Models\InstitutionUser;
use App\Models\UsageStatistic;
use App\Models\User;

class UsageStatisticPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'institution-admin']);
    }

    public function view(User $user, UsageStatistic $usageStatistic): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        $institutionUser = $this->activeInstitutionUser($user);

        return $institutionUser
            && (int) $institutionUser->institution_id ===
                (int) $usageStatistic->institution_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function update(User $user, UsageStatistic $usageStatistic): bool
    {
        return $user->hasRole('super-admin');
    }

    public function delete(User $user, UsageStatistic $usageStatistic): bool
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

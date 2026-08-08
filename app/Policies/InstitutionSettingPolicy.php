<?php

namespace App\Policies;

use App\Models\InstitutionSetting;
use App\Models\InstitutionUser;
use App\Models\User;

class InstitutionSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'institution-admin']);
    }

    public function view(User $user, InstitutionSetting $institutionSetting): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        $institutionUser = $this->activeInstitutionUser($user);

        return $institutionUser
            && (int) $institutionUser->institution_id ===
                (int) $institutionSetting->institution_id;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'institution-admin']);
    }

    public function update(User $user, InstitutionSetting $institutionSetting): bool
    {
        return $this->view($user, $institutionSetting);
    }

    public function delete(User $user, InstitutionSetting $institutionSetting): bool
    {
        return $this->view($user, $institutionSetting);
    }

    private function activeInstitutionUser(User $user): ?InstitutionUser
    {
        return InstitutionUser::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }
}

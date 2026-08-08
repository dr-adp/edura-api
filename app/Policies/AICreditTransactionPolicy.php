<?php

namespace App\Policies;

use App\Models\AICreditTransaction;
use App\Models\InstitutionUser;
use App\Models\User;

class AICreditTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'institution-admin']);
    }

    public function view(User $user, AICreditTransaction $aiCreditTransaction): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        $institutionUser = $this->activeInstitutionUser($user);

        return $institutionUser
            && (int) $institutionUser->institution_id ===
                (int) $aiCreditTransaction->institution_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function update(User $user, AICreditTransaction $aiCreditTransaction): bool
    {
        return false;
    }

    public function delete(User $user, AICreditTransaction $aiCreditTransaction): bool
    {
        return false;
    }

    private function activeInstitutionUser(User $user): ?InstitutionUser
    {
        return InstitutionUser::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }
}

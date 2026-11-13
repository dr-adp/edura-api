<?php

namespace App\Support;

use App\Models\InstitutionUser;
use App\Models\User;

class InstitutionAccess
{
    /** @return list<int> */
    public function institutionIdsFor(User $user): array
    {
        return InstitutionUser::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('institution_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function hasActiveMembershipIn(User $user, int $institutionId): bool
    {
        return InstitutionUser::query()
            ->where('user_id', $user->id)
            ->where('institution_id', $institutionId)
            ->where('status', 'active')
            ->exists();
    }

    public function institutionIdFor(User $user): ?int
    {
        return InstitutionUser::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('institution_id');
    }

    public function canAccess(User $user, int $institutionId): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        return $user->hasRole('institution-admin')
            && (int) $this->institutionIdFor($user) === $institutionId;
    }
}

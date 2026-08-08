<?php

namespace App\Policies;

use App\Models\ActivityLog;
use App\Models\InstitutionUser;
use App\Models\User;

class AuditLogPolicy
{
    private const VIEW_PERMISSION = 'view audit logs';

    public function viewAny(User $user): bool
    {
        return $this->canViewAuditLogs($user);
    }

    public function view(User $user, ActivityLog $activityLog): bool
    {
        if (! $this->canViewAuditLogs($user)) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return true;
        }

        $institutionUser = $this->activeInstitutionUser($user);

        return $institutionUser
            && $activityLog->institution_id !== null
            && (int) $institutionUser->institution_id ===
            (int) $activityLog->institution_id;
    }

    private function canViewAuditLogs(User $user): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        if (! $user->hasRole('institution-admin')) {
            return false;
        }

        if (! $this->activeInstitutionUser($user)) {
            return false;
        }

        return $user->can(self::VIEW_PERMISSION);
    }

    private function activeInstitutionUser(User $user): ?InstitutionUser
    {
        return InstitutionUser::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }
}

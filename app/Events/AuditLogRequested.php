<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;

class AuditLogRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $action,
        public readonly string $description,
        public readonly ?Model $auditable = null,
        public readonly array $metadata = [],
        public readonly ?int $institutionId = null,
        public readonly ?User $user = null,
        public readonly ?Request $request = null,
        public readonly ?string $module = null,
        public readonly ?array $oldValues = null,
        public readonly ?array $newValues = null,
        public readonly bool $useAuthenticatedUser = true
    ) {}
}

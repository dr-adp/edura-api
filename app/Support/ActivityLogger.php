<?php

namespace App\Support;

use App\Services\AuditLogService;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    public static function log(
        string $module,
        string $action,
        string $description,
        $model = null,
        array $properties = []
    ): void {
        app(AuditLogService::class)->recordCustom(
            action: $action,
            description: $description,
            auditable: $model,
            metadata: $properties,
            user: Auth::user(),
            request: app()->bound('request') ? request() : null,
            module: $module
        );
    }
}

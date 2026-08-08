<?php

namespace App\Listeners;

use App\Events\AuditLogRequested;
use App\Services\AuditLogService;

class RecordAuditLog
{
    public function __construct(
        private readonly AuditLogService $auditLogService
    ) {}

    public function handle(AuditLogRequested $event): void
    {
        $this->auditLogService->recordCustom(
            action: $event->action,
            description: $event->description,
            auditable: $event->auditable,
            metadata: $event->metadata,
            institutionId: $event->institutionId,
            user: $event->user,
            request: $event->request,
            module: $event->module,
            oldValues: $event->oldValues,
            newValues: $event->newValues,
            useAuthenticatedUser: $event->useAuthenticatedUser
        );
    }
}

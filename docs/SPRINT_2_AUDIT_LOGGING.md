# Sprint 2 Audit Logging and Activity Timeline

## Architecture

EDURA uses the existing `activity_logs` table as the enterprise audit log backing store. This avoids a duplicate audit subsystem while adding the fields needed for production audit trails:

- `institution_id` for SaaS tenant isolation
- `user_id` for authenticated actor context
- `action` for extensible audit verbs
- `auditable_type` and `auditable_id` for affected records
- `old_values` and `new_values` for meaningful before/after changes
- `metadata` for safe contextual data
- `ip_address`, `user_agent`, and `request_id` for request context

Writes go through `App\Services\AuditLogService`. Reads for the activity timeline also go through this service so tenant scoping and filters stay consistent.

## Recording Audit Events

Inject `AuditLogService` into services, listeners, jobs, or observers.

```php
use App\Services\AuditLogService;

public function __construct(
    private readonly AuditLogService $auditLogService
) {
}

$this->auditLogService->recordCustom(
    action: 'granted',
    description: 'AI credits granted.',
    auditable: $creditTransaction,
    metadata: ['source' => 'manual-adjustment']
);
```

For CRUD-style model events, use the convenience methods:

```php
$this->auditLogService->recordCreated($model);
$this->auditLogService->recordUpdated($model);
$this->auditLogService->recordDeleted($model);
$this->auditLogService->recordRestored($model);
```

System-generated actions are supported by passing `useAuthenticatedUser: false` and a trusted institution id:

```php
$this->auditLogService->recordCustom(
    action: 'expired',
    description: 'Subscription expired automatically.',
    institutionId: $institutionId,
    module: 'Subscription',
    useAuthenticatedUser: false
);
```

## Domain Event Integration

Future modules can dispatch `App\Events\AuditLogRequested`. The `RecordAuditLog` listener records it synchronously.

```php
AuditLogRequested::dispatch(
    action: 'assigned',
    description: 'Feature assigned to institution.',
    auditable: $feature,
    institutionId: $institutionId,
    module: 'Feature'
);
```

## Opt-in Model Auditing

`App\Models\Concerns\Auditable` provides an explicit opt-in trait for future models. Do not add it globally. High-volume modules should choose deliberately which models and actions need audit trails.

The existing `CourseObserver` is registered as an opt-in example and records course create, update, delete, and restore actions.

## API Endpoints

Audit timeline endpoints are protected by Sanctum, role middleware, and `AuditLogPolicy`.

- `GET /api/audit-logs`
- `GET /api/audit-logs/{id}`

Supported filters:

- `institution_id` for super-admin cross-tenant filtering
- `action`
- `user_id`
- `auditable_type`
- `auditable_id`
- `date_from`
- `date_to`
- `search`
- `page`
- `per_page`

Institution admins are always scoped to their own active `InstitutionUser` institution, even if they pass another `institution_id`.

## Security

Audit logs are sensitive. Access is limited to super admins and institution admins through `AuditLogPolicy`; institution admins can view only their own institution logs.

Sensitive values are redacted before storage. Redacted keys include passwords, password hashes, tokens, API keys, private keys, credentials, secrets, encrypted payloads, and related variants. Encrypted institution setting values are never written to audit logs.

The audit API validates all filters with `AuditLogFilterRequest` and does not accept arbitrary query fragments.

## Performance

Audit writes are synchronous to preserve integrity for security and commercial operations. Timeline reads use pagination, newest-first ordering, eager loading with selected columns, and indexes for common filters.

Audit logs are not automatically deleted. Retention or archival should be implemented later as an explicit compliance decision.

## Tests

Sprint 2 coverage lives in `tests/Feature/AuditLogTest.php` and covers:

- audit record creation
- authenticated actor and institution context
- system-generated records
- old/new values
- sensitive data redaction
- authorization and tenant isolation
- pagination, ordering, and filters
- event listener integration
- observer integration

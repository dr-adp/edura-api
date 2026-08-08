<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\InstitutionSetting;
use App\Models\User;
use App\Support\InstitutionAccess;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Exceptions\DomainException;
use Illuminate\Support\Str;

class AuditLogService
{
    private const REDACTED = '[REDACTED]';

    private const IGNORED_ATTRIBUTES = [
        'created_at',
        'updated_at',
        'deleted_at',
        'remember_token',
    ];

    private const SENSITIVE_KEY_FRAGMENTS = [
        'api_key',
        'apikey',
        'authorization',
        'client_secret',
        'credential',
        'credentials',
        'encrypted',
        'encryption_key',
        'hash',
        'password',
        'private_key',
        'refresh_token',
        'secret',
        'smtp_password',
        'token',
    ];

    public function __construct(
        private readonly InstitutionAccess $institutionAccess
    ) {}

    public function record(
        string $action,
        ?string $description = null,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $metadata = [],
        ?int $institutionId = null,
        ?User $user = null,
        ?Request $request = null,
        ?string $module = null,
        ?string $auditableType = null,
        ?int $auditableId = null,
        bool $useAuthenticatedUser = true
    ): ActivityLog {
        $request ??= $this->currentRequest();
        $user = $this->resolveUser($user, $useAuthenticatedUser);
        $institutionId = $this->resolveInstitutionId(
            $institutionId,
            $auditable,
            $user
        );

        $oldValues = $this->sanitizeValues(
            $this->filterAuditableValues($oldValues),
            $auditable
        );
        $newValues = $this->sanitizeValues(
            $this->filterAuditableValues($newValues),
            $auditable
        );
        $metadata = $this->sanitizeValues($metadata, $auditable) ?? [];

        $auditableType ??= $auditable ? $auditable::class : null;
        $auditableId ??= $auditable?->getKey();
        $module ??= $auditable ? class_basename($auditable) : 'System';
        $description ??= $this->defaultDescription($action, $auditable);

        return DB::transaction(fn(): ActivityLog => ActivityLog::create([
            'institution_id' => $institutionId,
            'user_id' => $user?->id,
            'module' => $module,
            'action' => Str::lower($action),
            'description' => $description,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => $this->requestId($request),
            'model_type' => $auditableType,
            'model_id' => $auditableId,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata ?: null,
            'properties' => $metadata ?: null,
        ]));
    }

    public function recordCreated(
        Model $auditable,
        ?string $description = null,
        array $metadata = [],
        ?User $user = null,
        ?Request $request = null
    ): ActivityLog {
        return $this->record(
            action: 'created',
            description: $description,
            auditable: $auditable,
            newValues: $this->modelValues($auditable),
            metadata: $metadata,
            user: $user,
            request: $request
        );
    }

    public function recordUpdated(
        Model $auditable,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $metadata = [],
        ?User $user = null,
        ?Request $request = null
    ): ?ActivityLog {
        [$oldValues, $newValues] = $oldValues === null && $newValues === null
            ? $this->changedModelValues($auditable)
            : [$oldValues, $newValues];

        if (
            empty($this->filterAuditableValues($oldValues)) &&
            empty($this->filterAuditableValues($newValues))
        ) {
            return null;
        }

        return $this->record(
            action: 'updated',
            description: $description,
            auditable: $auditable,
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: $metadata,
            user: $user,
            request: $request
        );
    }

    public function recordDeleted(
        Model $auditable,
        ?string $description = null,
        array $metadata = [],
        ?User $user = null,
        ?Request $request = null
    ): ActivityLog {
        return $this->record(
            action: 'deleted',
            description: $description,
            auditable: $auditable,
            oldValues: $this->modelValues($auditable),
            metadata: $metadata,
            user: $user,
            request: $request
        );
    }

    public function recordRestored(
        Model $auditable,
        ?string $description = null,
        array $metadata = [],
        ?User $user = null,
        ?Request $request = null
    ): ActivityLog {
        return $this->record(
            action: 'restored',
            description: $description,
            auditable: $auditable,
            newValues: $this->modelValues($auditable),
            metadata: $metadata,
            user: $user,
            request: $request
        );
    }

    public function recordCustom(
        string $action,
        string $description,
        ?Model $auditable = null,
        array $metadata = [],
        ?int $institutionId = null,
        ?User $user = null,
        ?Request $request = null,
        ?string $module = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        bool $useAuthenticatedUser = true
    ): ActivityLog {
        return $this->record(
            action: $action,
            description: $description,
            auditable: $auditable,
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: $metadata,
            institutionId: $institutionId,
            user: $user,
            request: $request,
            module: $module,
            useAuthenticatedUser: $useAuthenticatedUser
        );
    }

    public function timeline(User $viewer, array $filters): LengthAwarePaginator
    {
        $query = ActivityLog::query()
            ->with([
                'institution:id,name,code',
                'user:id,name,email',
            ])
            ->select([
                'id',
                'institution_id',
                'user_id',
                'module',
                'action',
                'description',
                'ip_address',
                'user_agent',
                'request_id',
                'auditable_type',
                'auditable_id',
                'model_type',
                'model_id',
                'old_values',
                'new_values',
                'metadata',
                'properties',
                'created_at',
                'updated_at',
            ]);

        if ($viewer->hasRole('institution-admin')) {
            $query->where(
                'institution_id',
                $this->institutionAccess->institutionIdFor($viewer)
            );
        } elseif (isset($filters['institution_id'])) {
            $query->where('institution_id', (int) $filters['institution_id']);
        }

        $query
            ->when(
                isset($filters['action']),
                fn($query) => $query->where('action', Str::lower($filters['action']))
            )
            ->when(
                isset($filters['user_id']),
                fn($query) => $query->where('user_id', (int) $filters['user_id'])
            )
            ->when(
                isset($filters['auditable_type']),
                fn($query) => $query->where('auditable_type', $filters['auditable_type'])
            )
            ->when(
                isset($filters['auditable_id']),
                fn($query) => $query->where('auditable_id', (int) $filters['auditable_id'])
            )
            ->when(isset($filters['date_from']), function ($query) use ($filters) {
                $query->where(
                    'created_at',
                    '>=',
                    Carbon::parse($filters['date_from'])->startOfDay()
                );
            })
            ->when(isset($filters['date_to']), function ($query) use ($filters) {
                $query->where(
                    'created_at',
                    '<=',
                    Carbon::parse($filters['date_to'])->endOfDay()
                );
            })
            ->when(isset($filters['search']), function ($query) use ($filters) {
                $term = addcslashes($filters['search'], '%_\\');

                $query->where('description', 'like', '%' . $term . '%');
            });

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20));
    }

    private function resolveUser(?User $user, bool $useAuthenticatedUser): ?User
    {
        if ($user) {
            return $user;
        }

        return $useAuthenticatedUser ? Auth::user() : null;
    }

    private function resolveInstitutionId(
        ?int $institutionId,
        ?Model $auditable,
        ?User $user
    ): ?int {
        $auditableInstitutionId = null;

        if ($auditable && isset($auditable->institution_id)) {
            $auditableInstitutionId = (int) $auditable->institution_id;
        }

        /*
     * Super-admin may explicitly record an audit entry
     * for a particular institution.
     */
        if (
            $institutionId !== null &&
            $user?->hasRole('super-admin')
        ) {
            return $institutionId;
        }

        /*
     * If the auditable model belongs to an institution,
     * use that institution as the primary tenant context.
     */
        if ($auditableInstitutionId !== null) {
            if (
                $institutionId !== null &&
                $institutionId !== $auditableInstitutionId
            ) {
                throw new DomainException(
                    'Audit institution does not match the auditable record.',
                    [
                        'institution_id' => $institutionId,
                        'auditable_institution_id' => $auditableInstitutionId,
                    ]
                );
            }

            $resolvedInstitutionId = $auditableInstitutionId;
        } elseif ($institutionId !== null) {
            /*
         * Explicit institution context without an auditable model
         * is allowed only when the authenticated user's institution
         * can be verified.
         */
            $resolvedInstitutionId = $institutionId;
        } elseif ($user) {
            $resolvedInstitutionId = $this->institutionAccess
                ->institutionIdFor($user);
        } else {
            return null;
        }

        /*
     * For non-super-admin users, verify the institution when
     * an institution relationship can be determined.
     *
     * Some EDURA users, such as teachers, may belong to an
     * institution through their domain profile rather than
     * through InstitutionUser. In that case the auditable
     * model's institution remains the authoritative context.
     */
        if ($user && ! $user->hasRole('super-admin')) {
            $userInstitutionId = $this->institutionAccess
                ->institutionIdFor($user);

            if (
                $userInstitutionId !== null &&
                (int) $resolvedInstitutionId !== (int) $userInstitutionId
            ) {
                throw new DomainException(
                    'Audit institution does not match the authenticated user institution.',
                    [
                        'institution_id' => $resolvedInstitutionId,
                        'user_institution_id' => $userInstitutionId,
                    ]
                );
            }

            /*
         * If the user has no InstitutionUser context, an explicit
         * institution without an auditable institution is not safe.
         */
            if (
                $userInstitutionId === null &&
                $auditableInstitutionId === null &&
                $institutionId !== null
            ) {
                throw new DomainException(
                    'Unable to verify the authenticated user institution.',
                    [
                        'institution_id' => $resolvedInstitutionId,
                        'user_id' => $user->id,
                    ]
                );
            }
        }

        return $resolvedInstitutionId;
    }

    private function modelValues(Model $model): array
    {
        return $this->filterAuditableValues($model->getAttributes());
    }

    private function changedModelValues(Model $model): array
    {
        $changedKeys = array_keys($this->filterAuditableValues($model->getChanges()));

        return [
            Arr::only($model->getOriginal(), $changedKeys),
            Arr::only($model->getAttributes(), $changedKeys),
        ];
    }

    private function filterAuditableValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return Arr::except($values, self::IGNORED_ATTRIBUTES);
    }

    private function sanitizeValues(?array $values, ?Model $auditable): ?array
    {
        if ($values === null) {
            return null;
        }

        $sanitized = [];

        foreach ($values as $key => $value) {
            $sanitized[$key] = $this->shouldRedact($key, $value, $auditable, $values)
                ? self::REDACTED
                : $this->sanitizeValue($value, $auditable);
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value, ?Model $auditable): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return $this->sanitizeValues($value, $auditable);
    }

    private function shouldRedact(
        string|int $key,
        mixed $value,
        ?Model $auditable,
        array $record
    ): bool {
        $normalized = Str::of((string) $key)
            ->snake()
            ->lower()
            ->replace(['-', '.', ' '], '_')
            ->value();

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        if (! $auditable instanceof InstitutionSetting) {
            return false;
        }

        if ($normalized !== 'value') {
            return false;
        }

        $settingKey = (string) ($record['key'] ?? $auditable->key ?? '');
        $isEncrypted = (bool) ($record['is_encrypted'] ?? $auditable->is_encrypted ?? false);

        return $isEncrypted || $this->looksSensitive($settingKey);
    }

    private function looksSensitive(string $key): bool
    {
        $normalized = Str::of($key)
            ->snake()
            ->lower()
            ->replace(['-', '.', ' '], '_')
            ->value();

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }

    private function requestId(?Request $request): ?string
    {
        if (! $request) {
            return null;
        }

        return $request->headers->get('X-Request-Id')
            ?? $request->headers->get('X-Correlation-Id');
    }

    private function defaultDescription(string $action, ?Model $auditable): string
    {
        if (! $auditable) {
            return Str::headline($action) . '.';
        }

        return class_basename($auditable) . ' ' . Str::lower($action) . '.';
    }
}

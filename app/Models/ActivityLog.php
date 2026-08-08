<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'user_id',
        'module',
        'action',
        'description',
        'ip_address',
        'user_agent',
        'model_type',
        'model_id',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'metadata',
        'properties',
        'request_id',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
        'properties' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'auditable_type', 'auditable_id');
    }

    public function model(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'model_type', 'model_id');
    }

    public function scopeForInstitution(
        Builder $query,
        ?int $institutionId
    ): Builder {
        return $institutionId
            ? $query->where('institution_id', $institutionId)
            : $query->whereNull('institution_id');
    }

    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeForAuditable(
        Builder $query,
        string $auditableType,
        ?int $auditableId = null
    ): Builder {
        return $query
            ->where('auditable_type', $auditableType)
            ->when(
                $auditableId,
                fn (Builder $query) => $query->where('auditable_id', $auditableId)
            );
    }
}

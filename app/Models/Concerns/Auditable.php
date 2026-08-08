<?php

namespace App\Models\Concerns;

use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            app(AuditLogService::class)->recordCreated($model);
        });

        static::updated(function (Model $model): void {
            app(AuditLogService::class)->recordUpdated($model);
        });

        static::deleted(function (Model $model): void {
            app(AuditLogService::class)->recordDeleted($model);
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function (Model $model): void {
                app(AuditLogService::class)->recordRestored($model);
            });
        }
    }
}

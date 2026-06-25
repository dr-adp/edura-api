<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
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

        $user = Auth::user();

        ActivityLog::create([
            'institution_id' => institutionId(),
            'user_id' => $user?->id,
            'module' => $module,
            'action' => $action,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'model_type' => $model ? get_class($model) : null,
            'model_id' => $model?->id,
            'properties' => $properties,
        ]);
    }
}

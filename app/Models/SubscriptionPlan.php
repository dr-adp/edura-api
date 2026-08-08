<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'price',
        'billing_cycle',
        'trial_days',
        'max_teachers',
        'max_students',
        'max_courses',
        'storage_limit_mb',
        'included_ai_credits',
        'api_request_limit',
        'limits',
        'allow_live_classes',
        'allow_recorded_classes',
        'allow_ai_reports',
        'allow_hand_sign_module',
        'allow_noticeboard',
        'allow_notes_upload',
        'description',
        'metadata',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'trial_days' => 'integer',
        'max_teachers' => 'integer',
        'max_students' => 'integer',
        'max_courses' => 'integer',
        'storage_limit_mb' => 'integer',
        'included_ai_credits' => 'decimal:4',
        'api_request_limit' => 'integer',
        'limits' => 'array',
        'allow_live_classes' => 'boolean',
        'allow_recorded_classes' => 'boolean',
        'allow_ai_reports' => 'boolean',
        'allow_hand_sign_module' => 'boolean',
        'allow_noticeboard' => 'boolean',
        'allow_notes_upload' => 'boolean',
        'metadata' => 'array',
        'sort_order' => 'integer',
    ];

    public function institutionSubscriptions()
    {
        return $this->hasMany(InstitutionSubscription::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function planFeatures()
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function features()
    {
        return $this->belongsToMany(Feature::class, 'plan_features')
            ->withPivot(['enabled', 'value', 'status', 'metadata'])
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeCode(Builder $query, string $code): Builder
    {
        return $query->where('code', $code);
    }

    public function limit(string $metric): ?float
    {
        $limits = $this->limits ?? [];

        if (array_key_exists($metric, $limits)) {
            return $limits[$metric] === null ? null : (float) $limits[$metric];
        }

        return match ($metric) {
            'teachers' => (float) $this->max_teachers,
            'students' => (float) $this->max_students,
            'courses' => (float) $this->max_courses,
            'storage_mb' => (float) $this->storage_limit_mb,
            'api_requests' => $this->api_request_limit === null
                ? null
                : (float) $this->api_request_limit,
            'ai_credits' => (float) $this->included_ai_credits,
            default => null,
        };
    }

    public function hasFeature(string $featureCode): bool
    {
        return $this->planFeatures()
            ->whereHas('feature', function (Builder $query) use ($featureCode) {
                $query->where('code', $featureCode)
                    ->where('status', 'active');
            })
            ->where('enabled', true)
            ->where('status', 'active')
            ->exists();
    }
}

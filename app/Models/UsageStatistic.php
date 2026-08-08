<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UsageStatistic extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'institution_id',
        'subscription_id',
        'metric',
        'period_start',
        'period_end',
        'used_value',
        'limit_value',
        'metadata',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'used_value' => 'decimal:4',
        'limit_value' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function scopeForInstitution(Builder $query, int $institutionId): Builder
    {
        return $query->where('institution_id', $institutionId);
    }

    public function scopeMetric(Builder $query, string $metric): Builder
    {
        return $query->where('metric', $metric);
    }

    public function scopeForPeriod(Builder $query, string $start, string $end): Builder
    {
        return $query->whereDate('period_start', $start)
            ->whereDate('period_end', $end);
    }

    public function isOverLimit(): bool
    {
        return $this->limit_value !== null
            && (float) $this->used_value > (float) $this->limit_value;
    }
}

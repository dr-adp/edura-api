<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AICreditTransaction extends Model
{
    use HasFactory;

    protected $table = 'ai_credit_transactions';

    protected $fillable = [
        'institution_id',
        'subscription_id',
        'transaction_type',
        'credits',
        'balance_after',
        'source',
        'reference_type',
        'reference_id',
        'description',
        'metadata',
        'expires_at',
        'created_by_id',
    ];

    protected $casts = [
        'credits' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'metadata' => 'array',
        'expires_at' => 'datetime',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function scopeForInstitution(Builder $query, int $institutionId): Builder
    {
        return $query->where('institution_id', $institutionId);
    }

    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('transaction_type', $type);
    }
}

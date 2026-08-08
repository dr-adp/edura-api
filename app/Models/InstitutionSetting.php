<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstitutionSetting extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'institution_id',
        'group',
        'key',
        'value',
        'value_type',
        'is_public',
        'is_encrypted',
        'status',
        'metadata',
    ];

    protected $casts = [
        'value' => 'array',
        'is_public' => 'boolean',
        'is_encrypted' => 'boolean',
        'metadata' => 'array',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    public function scopeForKey(Builder $query, string $group, string $key): Builder
    {
        return $query->where('group', $group)->where('key', $key);
    }
}

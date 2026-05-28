<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'price',
        'billing_cycle',
        'max_teachers',
        'max_students',
        'max_courses',
        'storage_limit_mb',
        'allow_live_classes',
        'allow_recorded_classes',
        'allow_ai_reports',
        'allow_hand_sign_module',
        'allow_noticeboard',
        'allow_notes_upload',
        'description',
        'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'allow_live_classes' => 'boolean',
        'allow_recorded_classes' => 'boolean',
        'allow_ai_reports' => 'boolean',
        'allow_hand_sign_module' => 'boolean',
        'allow_noticeboard' => 'boolean',
        'allow_notes_upload' => 'boolean',
    ];
}

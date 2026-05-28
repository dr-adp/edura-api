<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InstitutionSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'subscription_plan_id',
        'start_date',
        'end_date',
        'amount_paid',
        'payment_status',
        'status',
        'payment_reference',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'amount_paid' => 'decimal:2',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function subscriptionPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }
}

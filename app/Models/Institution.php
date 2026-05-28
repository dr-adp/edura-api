<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Institution extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'email',
        'phone',
        'website',
        'address',
        'city',
        'state',
        'country',
        'pincode',
        'status',
    ];

    public function subscriptions()
    {
        return $this->hasMany(InstitutionSubscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(InstitutionSubscription::class)
            ->where('status', 'active')
            ->latestOfMany();
    }

    public function departments()
    {
        return $this->hasMany(Department::class);
    }
}

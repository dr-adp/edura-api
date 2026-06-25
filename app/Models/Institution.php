<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'email',
        'phone',
        'website',
        'logo',
        'address',
        'city',
        'state',
        'country',
        'pincode',
        'status',
    ];

    protected $appends = [
        'logo_url',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo
            ? asset('storage/' . $this->logo)
            : null;
    }

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

    public function batches()
    {
        return $this->hasMany(Batch::class);
    }

    public function teachers()
    {
        return $this->hasMany(TeacherProfile::class);
    }

    public function students()
    {
        return $this->hasMany(StudentProfile::class);
    }

    public function parents()
    {
        return $this->hasMany(ParentProfile::class);
    }

    public function courses()
    {
        return $this->hasMany(Course::class);
    }

    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function liveClasses()
    {
        return $this->hasMany(LiveClass::class);
    }

    public function certificateSetting()
    {
        return $this->hasOne(CertificateSetting::class);
    }
}

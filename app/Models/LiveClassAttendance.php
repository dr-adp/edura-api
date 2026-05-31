<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LiveClassAttendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'live_class_id',
        'student_profile_id',
        'attendance_status',
        'joined_at',
        'left_at',
        'duration_minutes',
        'remarks',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function liveClass()
    {
        return $this->belongsTo(LiveClass::class);
    }

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }
}

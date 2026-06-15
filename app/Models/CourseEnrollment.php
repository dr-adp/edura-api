<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CourseEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'student_profile_id',
        'enrollment_date',
        'payment_status',
        'amount_paid',
        'progress_percentage',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'progress_percentage' => 'decimal:2',
        'enrollment_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function lessonProgress()
    {
        return $this->hasMany(LessonProgress::class);
    }
}

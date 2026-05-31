<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LessonProgress extends Model
{
    use HasFactory;

    protected $table = 'lesson_progress';

    protected $fillable = [
        'course_enrollment_id',
        'lesson_id',
        'status',
        'progress_percentage',
        'watch_time_minutes',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'progress_percentage' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function courseEnrollment()
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }
}

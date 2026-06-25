<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class LiveClass extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'institution_id',
        'course_id',
        'course_section_id',
        'lesson_id',
        'teacher_profile_id',
        'batch_id',
        'title',
        'description',
        'platform',
        'meeting_url',
        'meeting_id',
        'meeting_password',
        'scheduled_start_time',
        'scheduled_end_time',
        'recording_url',
        'status',
    ];

    protected $casts = [
        'scheduled_start_time' => 'datetime',
        'scheduled_end_time' => 'datetime',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function courseSection()
    {
        return $this->belongsTo(CourseSection::class);
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function batch()
    {
        return $this->belongsTo(Batch::class);
    }

    public function attendances()
    {
        return $this->hasMany(LiveClassAttendance::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Assignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'course_section_id',
        'lesson_id',
        'teacher_profile_id',
        'title',
        'short_description',
        'instructions',
        'maximum_marks',
        'available_from',
        'due_date',
        'allow_late_submission',
        'status',
    ];

    protected $casts = [
        'maximum_marks' => 'decimal:2',
        'available_from' => 'datetime',
        'due_date' => 'datetime',
        'allow_late_submission' => 'boolean',
    ];

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

    public function submissions()
    {
        return $this->hasMany(AssignmentSubmission::class);
    }
}

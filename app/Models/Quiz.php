<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Quiz extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'course_section_id',
        'lesson_id',
        'teacher_profile_id',
        'title',
        'description',
        'duration_minutes',
        'total_marks',
        'passing_marks',
        'shuffle_questions',
        'show_result_immediately',
        'available_from',
        'available_until',
        'status',
    ];

    protected $casts = [
        'total_marks' => 'decimal:2',
        'passing_marks' => 'decimal:2',
        'shuffle_questions' => 'boolean',
        'show_result_immediately' => 'boolean',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
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

    public function quizQuestions()
    {
        return $this->hasMany(QuizQuestion::class)
            ->orderBy('sort_order');
    }

    public function attempts()
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function questions()
    {
        return $this->hasMany(QuizQuestion::class)
            ->orderBy('sort_order');
    }
}

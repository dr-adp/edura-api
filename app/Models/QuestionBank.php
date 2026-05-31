<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class QuestionBank extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'lesson_id',
        'question_text',
        'question_description',
        'question_type',
        'difficulty',
        'marks',
        'topic',
        'explanation',
        'status',
    ];

    protected $casts = [
        'marks' => 'decimal:2',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function options()
    {
        return $this->hasMany(QuestionOption::class)
            ->orderBy('sort_order');
    }
}

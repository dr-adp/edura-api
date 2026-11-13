<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuizAttempt extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'quiz_id',
        'student_profile_id',
        'attempt_number',
        'started_at',
        'submitted_at',
        'evaluated_at',
        'total_marks',
        'marks_obtained',
        'percentage',
        'result_status',
        'status',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'evaluated_at' => 'datetime',
        'total_marks' => 'decimal:2',
        'marks_obtained' => 'decimal:2',
        'percentage' => 'decimal:2',
    ];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function answers()
    {
        return $this->hasMany(QuizAnswer::class);
    }

    public function scopeInstitutionallyConsistent(Builder $query): Builder
    {
        return $query->whereHas('quiz.course', function (Builder $courseQuery) {
            $courseQuery->whereIn('courses.institution_id', function ($studentQuery) {
                $studentQuery->select('student_profiles.institution_id')
                    ->from('student_profiles')
                    ->whereColumn(
                        'student_profiles.id',
                        'quiz_attempts.student_profile_id'
                    );
            });
        });
    }

    public function hasConsistentInstitutions(): bool
    {
        $this->loadMissing(['quiz.course', 'studentProfile']);

        return $this->quiz?->course &&
            $this->studentProfile &&
            $this->quiz->course->institution_id !== null &&
            $this->studentProfile->institution_id !== null &&
            (int) $this->quiz->course->institution_id ===
                (int) $this->studentProfile->institution_id;
    }
}

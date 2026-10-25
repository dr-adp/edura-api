<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class Gradebook extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id',
        'student_profile_id',
        'assignment_marks',
        'quiz_marks',
        'total_marks',
        'maximum_marks',
        'percentage',
        'grade',
        'result_status',
    ];

    protected $casts = [
        'assignment_marks' => 'decimal:2',
        'quiz_marks' => 'decimal:2',
        'total_marks' => 'decimal:2',
        'maximum_marks' => 'decimal:2',
        'percentage' => 'decimal:2',
    ];

    public function scopeInstitutionallyConsistent(Builder $query): Builder
    {
        return $query->whereHas('course', function (Builder $courseQuery) {
            $courseQuery->whereIn('courses.institution_id', function ($studentQuery) {
                $studentQuery->select('student_profiles.institution_id')
                    ->from('student_profiles')
                    ->whereColumn(
                        'student_profiles.id',
                        'gradebooks.student_profile_id'
                    );
            });
        });
    }

    public function hasConsistentInstitutions(): bool
    {
        $this->loadMissing(['course', 'studentProfile']);

        return $this->course &&
            $this->studentProfile &&
            $this->course->institution_id !== null &&
            $this->studentProfile->institution_id !== null &&
            (int) $this->course->institution_id ===
                (int) $this->studentProfile->institution_id;
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function certificate()
    {
        return $this->hasOne(Certificate::class);
    }
}

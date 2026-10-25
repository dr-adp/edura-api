<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseEnrollment extends Model
{
    use HasFactory, SoftDeletes;

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

    public function scopeInstitutionallyConsistent(Builder $query): Builder
    {
        return $query
            ->whereHas('course')
            ->whereHas('studentProfile')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('courses')
                    ->join(
                        'student_profiles',
                        'student_profiles.id',
                        '=',
                        'course_enrollments.student_profile_id'
                    )
                    ->whereColumn('courses.id', 'course_enrollments.course_id')
                    ->whereColumn(
                        'courses.institution_id',
                        'student_profiles.institution_id'
                    );
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
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssignmentSubmission extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'assignment_id',
        'student_profile_id',
        'submission_text',
        'file_path',
        'external_url',
        'submitted_at',
        'is_late',
        'status',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'is_late' => 'boolean',
    ];

    protected $appends = [
        'file_url',
    ];

    public function getFileUrlAttribute(): ?string
    {
        return $this->file_path
            ? asset('storage/' . $this->file_path)
            : null;
    }

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function evaluation()
    {
        return $this->hasOne(AssignmentEvaluation::class);
    }

    public function scopeInstitutionallyConsistent(Builder $query): Builder
    {
        return $query->whereHas('assignment.course', function (Builder $courseQuery) {
            $courseQuery->whereIn('courses.institution_id', function ($studentQuery) {
                $studentQuery->select('student_profiles.institution_id')
                    ->from('student_profiles')
                    ->whereColumn(
                        'student_profiles.id',
                        'assignment_submissions.student_profile_id'
                    );
            });
        });
    }

    public function hasConsistentInstitutions(): bool
    {
        $this->loadMissing(['assignment.course', 'studentProfile']);

        return $this->assignment?->course &&
            $this->studentProfile &&
            $this->assignment->course->institution_id !== null &&
            $this->studentProfile->institution_id !== null &&
            (int) $this->assignment->course->institution_id ===
                (int) $this->studentProfile->institution_id;
    }
}

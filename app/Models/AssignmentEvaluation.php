<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssignmentEvaluation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'assignment_submission_id',
        'teacher_profile_id',
        'marks_obtained',
        'maximum_marks',
        'feedback',
        'result_status',
        'evaluated_at',
    ];

    protected $casts = [
        'marks_obtained' => 'decimal:2',
        'maximum_marks' => 'decimal:2',
        'evaluated_at' => 'datetime',
    ];

    public function assignmentSubmission()
    {
        return $this->belongsTo(AssignmentSubmission::class);
    }

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Gradebook extends Model
{
    use HasFactory;

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

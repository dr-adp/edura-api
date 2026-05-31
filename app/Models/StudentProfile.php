<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StudentProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'user_id',
        'department_id',
        'batch_id',
        'roll_number',
        'date_of_birth',
        'gender',
        'phone',
        'parent_name',
        'parent_phone',
        'address',
        'status',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function batch()
    {
        return $this->belongsTo(Batch::class);
    }
    public function courseEnrollments()
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function liveClassAttendances()
    {
        return $this->hasMany(LiveClassAttendance::class);
    }

    public function assignmentSubmissions()
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function quizAttempts()
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function gradebooks()
    {
        return $this->hasMany(Gradebook::class);
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TeacherProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'user_id',
        'department_id',
        'employee_code',
        'qualification',
        'specialization',
        'bio',
        'experience_years',
        'phone',
        'status',
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

    public function assignmentEvaluations()
    {
        return $this->hasMany(AssignmentEvaluation::class);
    }
}

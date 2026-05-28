<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ParentProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'user_id',
        'student_profile_id',
        'relationship',
        'occupation',
        'phone',
        'alternate_phone',
        'address',
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

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }
}

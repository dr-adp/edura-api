<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Certificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'student_profile_id',
        'gradebook_id',
        'certificate_number',
        'issued_date',
        'final_percentage',
        'final_grade',
        'status',
        'certificate_file',
        'remarks',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'final_percentage' => 'decimal:2',
    ];

    protected $appends = [
        'certificate_file_url',
    ];

    public function getCertificateFileUrlAttribute(): ?string
    {
        return $this->certificate_file
            ? asset('storage/' . $this->certificate_file)
            : null;
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function gradebook()
    {
        return $this->belongsTo(Gradebook::class);
    }
}

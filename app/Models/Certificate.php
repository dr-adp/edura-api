<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Certificate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id',
        'student_profile_id',
        'gradebook_id',
        'certificate_number',
        'certificate_uuid',
        'verification_token',
        'issued_date',
        'final_percentage',
        'final_grade',
        'status',
        'verification_status',
        'certificate_file',
        'remarks',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'final_percentage' => 'decimal:2',
    ];

    protected $hidden = [
        'certificate_file',
    ];

    protected $appends = [
        'public_verification_url',
    ];

    public function getPublicVerificationUrlAttribute(): ?string
    {
        return $this->verification_token
            ? url('/api/verify-certificate/' . $this->verification_token)
            : null;
    }

    public function isIssuedAndValid(): bool
    {
        return $this->status === 'issued' && $this->verification_status === 'valid';
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

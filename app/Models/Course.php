<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'department_id',
        'batch_id',
        'teacher_profile_id',
        'title',
        'slug',
        'short_description',
        'description',
        'thumbnail',
        'price',
        'course_type',
        'level',
        'language',
        'duration_hours',
        'certificate_enabled',
        'live_class_enabled',
        'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'certificate_enabled' => 'boolean',
        'live_class_enabled' => 'boolean',
    ];

    protected $appends = [
        'thumbnail_url',
    ];

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail
            ? asset('storage/' . $this->thumbnail)
            : null;
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function batch()
    {
        return $this->belongsTo(Batch::class);
    }

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }
    public function sections()
    {
        return $this->hasMany(CourseSection::class)
            ->orderBy('sort_order');
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class)
            ->orderBy('sort_order');
    }
    public function enrollments()
    {
        return $this->hasMany(CourseEnrollment::class);
    }
    public function liveClasses()
    {
        return $this->hasMany(LiveClass::class);
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }

    public function questionBanks()
    {
        return $this->hasMany(QuestionBank::class);
    }
}

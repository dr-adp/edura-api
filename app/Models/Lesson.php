<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Lesson extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'course_section_id',
        'title',
        'short_description',
        'content',
        'lesson_type',
        'video_url',
        'pdf_url',
        'external_resource_url',
        'duration_minutes',
        'is_preview',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'is_preview' => 'boolean',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function courseSection()
    {
        return $this->belongsTo(CourseSection::class);
    }

    public function resources()
    {
        return $this->hasMany(LessonResource::class)
            ->orderBy('sort_order');
    }

    public function progressRecords()
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function liveClasses()
    {
        return $this->hasMany(LiveClass::class);
    }
}

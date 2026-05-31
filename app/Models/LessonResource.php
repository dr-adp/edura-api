<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LessonResource extends Model
{
    use HasFactory;

    protected $fillable = [
        'lesson_id',
        'title',
        'resource_type',
        'video_provider',
        'content',
        'file_path',
        'external_url',
        'video_duration_minutes',
        'video_size_mb',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'video_size_mb' => 'decimal:2',
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

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }
}

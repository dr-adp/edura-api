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
        'content',
        'file_path',
        'external_url',
        'sort_order',
        'status',
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

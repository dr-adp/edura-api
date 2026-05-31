<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class QuizQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'question_bank_id',
        'marks',
        'sort_order',
    ];

    protected $casts = [
        'marks' => 'decimal:2',
    ];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function questionBank()
    {
        return $this->belongsTo(QuestionBank::class);
    }
}

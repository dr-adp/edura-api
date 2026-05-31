<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('quiz_id')
                ->constrained('quizzes')
                ->cascadeOnDelete();

            $table->foreignId('question_bank_id')
                ->constrained('question_banks')
                ->cascadeOnDelete();

            $table->decimal('marks', 8, 2)->default(1);
            $table->integer('sort_order')->default(1);

            $table->timestamps();

            $table->unique(['quiz_id', 'question_bank_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
    }
};

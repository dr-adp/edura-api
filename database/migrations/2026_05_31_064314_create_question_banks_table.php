<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_banks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();

            $table->string('question_text');
            $table->longText('question_description')->nullable();

            $table->enum('question_type', [
                'mcq',
                'true_false',
                'short_answer',
                'long_answer',
                'fill_blank'
            ])->default('mcq');

            $table->enum('difficulty', [
                'easy',
                'medium',
                'hard'
            ])->default('easy');

            $table->decimal('marks', 8, 2)->default(1);

            $table->string('topic')->nullable();
            $table->longText('explanation')->nullable();

            $table->enum('status', [
                'active',
                'inactive'
            ])->default('active');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_banks');
    }
};

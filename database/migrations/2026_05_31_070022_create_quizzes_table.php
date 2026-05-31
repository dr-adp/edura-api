<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnDelete();

            $table->foreignId('course_section_id')
                ->nullable()
                ->constrained('course_sections')
                ->nullOnDelete();

            $table->foreignId('lesson_id')
                ->nullable()
                ->constrained('lessons')
                ->nullOnDelete();

            $table->foreignId('teacher_profile_id')
                ->nullable()
                ->constrained('teacher_profiles')
                ->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->integer('duration_minutes')->nullable();
            $table->decimal('total_marks', 8, 2)->default(0);
            $table->decimal('passing_marks', 8, 2)->default(0);

            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('show_result_immediately')->default(true);

            $table->dateTime('available_from')->nullable();
            $table->dateTime('available_until')->nullable();

            $table->enum('status', [
                'draft',
                'published',
                'closed'
            ])->default('draft');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};

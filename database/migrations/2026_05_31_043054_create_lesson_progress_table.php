<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_enrollment_id')
                ->constrained('course_enrollments')
                ->cascadeOnDelete();

            $table->foreignId('lesson_id')
                ->constrained('lessons')
                ->cascadeOnDelete();

            $table->enum('status', [
                'not_started',
                'in_progress',
                'completed'
            ])->default('not_started');

            $table->decimal('progress_percentage', 5, 2)->default(0);

            $table->integer('watch_time_minutes')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique(['course_enrollment_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};

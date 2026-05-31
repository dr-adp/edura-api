<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {

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

            $table->text('short_description')->nullable();
            $table->longText('instructions')->nullable();

            $table->decimal('maximum_marks', 8, 2)->default(100);

            $table->dateTime('available_from')->nullable();
            $table->dateTime('due_date')->nullable();

            $table->boolean('allow_late_submission')->default(false);

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
        Schema::dropIfExists('assignments');
    }
};

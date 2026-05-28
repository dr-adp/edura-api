<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {

            $table->id();

            $table->foreignId('institution_id')
                ->nullable()
                ->constrained('institutions')
                ->nullOnDelete();

            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();

            $table->foreignId('batch_id')
                ->nullable()
                ->constrained('batches')
                ->nullOnDelete();

            $table->foreignId('teacher_profile_id')
                ->nullable()
                ->constrained('teacher_profiles')
                ->nullOnDelete();

            $table->string('title');
            $table->string('slug')->unique();

            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();

            $table->string('thumbnail')->nullable();

            $table->decimal('price', 10, 2)->default(0);

            $table->enum('course_type', [
                'free',
                'paid',
                'private'
            ])->default('free');

            $table->enum('level', [
                'beginner',
                'intermediate',
                'advanced'
            ])->default('beginner');

            $table->string('language')->default('English');

            $table->integer('duration_hours')->nullable();

            $table->boolean('certificate_enabled')->default(false);
            $table->boolean('live_class_enabled')->default(true);

            $table->enum('status', [
                'draft',
                'published',
                'archived'
            ])->default('draft');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};

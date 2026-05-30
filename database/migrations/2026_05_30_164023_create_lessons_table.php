<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {

            $table->id();

            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnDelete();

            $table->foreignId('course_section_id')
                ->nullable()
                ->constrained('course_sections')
                ->nullOnDelete();

            $table->string('title');

            $table->text('short_description')->nullable();
            $table->longText('content')->nullable();

            $table->enum('lesson_type', [
                'text',
                'video',
                'pdf',
                'mixed'
            ])->default('text');

            $table->string('video_url')->nullable();
            $table->string('pdf_url')->nullable();
            $table->string('external_resource_url')->nullable();

            $table->integer('duration_minutes')->nullable();

            $table->boolean('is_preview')->default(false);

            $table->integer('sort_order')->default(1);

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
        Schema::dropIfExists('lessons');
    }
};

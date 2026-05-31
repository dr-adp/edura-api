<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_classes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('course_section_id')->nullable()->constrained('course_sections')->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->foreignId('teacher_profile_id')->nullable()->constrained('teacher_profiles')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->enum('platform', [
                'google_meet',
                'zoom',
                'jitsi',
                'microsoft_teams',
                'other'
            ])->default('google_meet');

            $table->string('meeting_url');
            $table->string('meeting_id')->nullable();
            $table->string('meeting_password')->nullable();

            $table->dateTime('scheduled_start_time');
            $table->dateTime('scheduled_end_time')->nullable();

            $table->string('recording_url')->nullable();

            $table->enum('status', [
                'scheduled',
                'live',
                'completed',
                'cancelled'
            ])->default('scheduled');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_classes');
    }
};

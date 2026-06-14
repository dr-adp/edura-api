<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institution_id')
                ->constrained('institutions')
                ->cascadeOnDelete();

            $table->foreignId('batch_id')
                ->nullable()
                ->constrained('batches')
                ->nullOnDelete();

            $table->foreignId('course_id')
                ->nullable()
                ->constrained('courses')
                ->nullOnDelete();

            $table->foreignId('student_profile_id')
                ->constrained('student_profiles')
                ->cascadeOnDelete();

            $table->foreignId('marked_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->date('attendance_date');

            $table->enum('attendance_status', [
                'present',
                'absent',
                'late',
                'half_day',
                'excused',
            ])->default('present');

            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();

            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->unique(['student_profile_id', 'attendance_date']);
            $table->index(['institution_id', 'attendance_date']);
            $table->index(['batch_id', 'attendance_date']);
            $table->index(['course_id', 'attendance_date']);
            $table->index(['attendance_status', 'attendance_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_class_attendances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('live_class_id')
                ->constrained('live_classes')
                ->cascadeOnDelete();

            $table->foreignId('student_profile_id')
                ->constrained('student_profiles')
                ->cascadeOnDelete();

            $table->enum('attendance_status', [
                'present',
                'absent',
                'late',
                'excused'
            ])->default('present');

            $table->dateTime('joined_at')->nullable();
            $table->dateTime('left_at')->nullable();

            $table->integer('duration_minutes')->default(0);

            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->unique(['live_class_id', 'student_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_class_attendances');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('quiz_id')
                ->constrained('quizzes')
                ->cascadeOnDelete();

            $table->foreignId('student_profile_id')
                ->constrained('student_profiles')
                ->cascadeOnDelete();

            $table->integer('attempt_number')->default(1);

            $table->dateTime('started_at')->nullable();
            $table->dateTime('submitted_at')->nullable();

            $table->decimal('total_marks', 8, 2)->default(0);
            $table->decimal('marks_obtained', 8, 2)->default(0);
            $table->decimal('percentage', 5, 2)->default(0);

            $table->enum('result_status', [
                'pending',
                'passed',
                'failed'
            ])->default('pending');

            $table->enum('status', [
                'in_progress',
                'submitted',
                'evaluated',
                'cancelled'
            ])->default('in_progress');

            $table->timestamps();

            $table->unique(['quiz_id', 'student_profile_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
    }
};

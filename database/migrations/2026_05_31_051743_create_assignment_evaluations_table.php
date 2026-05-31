<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_evaluations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('assignment_submission_id')
                ->constrained('assignment_submissions')
                ->cascadeOnDelete();

            $table->foreignId('teacher_profile_id')
                ->nullable()
                ->constrained('teacher_profiles')
                ->nullOnDelete();

            $table->decimal('marks_obtained', 8, 2)->default(0);
            $table->decimal('maximum_marks', 8, 2)->default(100);

            $table->longText('feedback')->nullable();

            $table->enum('result_status', [
                'passed',
                'failed',
                'needs_improvement'
            ])->nullable();

            $table->dateTime('evaluated_at')->nullable();

            $table->timestamps();

            $table->unique('assignment_submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_evaluations');
    }
};

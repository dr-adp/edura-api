<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gradebooks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->cascadeOnDelete();

            $table->decimal('assignment_marks', 8, 2)->default(0);
            $table->decimal('quiz_marks', 8, 2)->default(0);
            $table->decimal('total_marks', 8, 2)->default(0);
            $table->decimal('maximum_marks', 8, 2)->default(0);
            $table->decimal('percentage', 5, 2)->default(0);

            $table->string('grade')->nullable();

            $table->enum('result_status', [
                'pending',
                'passed',
                'failed'
            ])->default('pending');

            $table->timestamps();

            $table->unique(['course_id', 'student_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gradebooks');
    }
};

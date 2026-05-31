<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_enrollments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnDelete();

            $table->foreignId('student_profile_id')
                ->constrained('student_profiles')
                ->cascadeOnDelete();

            $table->date('enrollment_date');

            $table->enum('payment_status', [
                'free',
                'pending',
                'paid',
                'failed',
                'refunded'
            ])->default('free');

            $table->decimal('amount_paid', 10, 2)->default(0);

            $table->decimal('progress_percentage', 5, 2)->default(0);

            $table->enum('status', [
                'active',
                'completed',
                'cancelled',
                'expired'
            ])->default('active');

            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique(['course_id', 'student_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_enrollments');
    }
};

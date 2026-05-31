<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->foreignId('gradebook_id')->nullable()->constrained('gradebooks')->nullOnDelete();

            $table->string('certificate_number')->unique();
            $table->date('issued_date')->nullable();

            $table->decimal('final_percentage', 5, 2)->default(0);
            $table->string('final_grade')->nullable();

            $table->enum('status', ['pending', 'issued', 'revoked'])->default('pending');

            $table->string('certificate_file')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->unique(['course_id', 'student_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};

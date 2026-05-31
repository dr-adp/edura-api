<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('assignment_id')
                ->constrained('assignments')
                ->cascadeOnDelete();

            $table->foreignId('student_profile_id')
                ->constrained('student_profiles')
                ->cascadeOnDelete();

            $table->longText('submission_text')->nullable();
            $table->string('file_path')->nullable();
            $table->string('external_url')->nullable();

            $table->dateTime('submitted_at')->nullable();

            $table->boolean('is_late')->default(false);

            $table->enum('status', [
                'draft',
                'submitted',
                'reviewed',
                'returned'
            ])->default('draft');

            $table->timestamps();

            $table->unique(['assignment_id', 'student_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
    }
};

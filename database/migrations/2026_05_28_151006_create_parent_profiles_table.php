<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institution_id')
                ->constrained('institutions')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('student_profile_id')
                ->constrained('student_profiles')
                ->cascadeOnDelete();

            $table->string('relationship')->nullable();

            $table->string('occupation')->nullable();
            $table->string('phone')->nullable();
            $table->string('alternate_phone')->nullable();

            $table->text('address')->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique(['institution_id', 'user_id', 'student_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_profiles');
    }
};
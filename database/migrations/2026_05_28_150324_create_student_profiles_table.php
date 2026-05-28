<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institution_id')
                ->constrained('institutions')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();

            $table->foreignId('batch_id')
                ->nullable()
                ->constrained('batches')
                ->nullOnDelete();

            $table->string('roll_number')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();

            $table->string('phone')->nullable();
            $table->string('parent_name')->nullable();
            $table->string('parent_phone')->nullable();

            $table->text('address')->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique(['institution_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};

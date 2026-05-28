<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institution_id')
                ->constrained('institutions')
                ->cascadeOnDelete();

            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();

            $table->string('name');
            $table->string('code');

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->enum('mode', ['offline', 'online', 'hybrid'])->default('online');
            $table->enum('status', ['active', 'inactive', 'completed'])->default('active');

            $table->text('description')->nullable();

            $table->timestamps();

            $table->unique(['institution_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};

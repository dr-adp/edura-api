<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institution_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('module', 100);

            $table->string('action', 100);

            $table->string('description');

            $table->string('ip_address', 45)->nullable();

            $table->text('user_agent')->nullable();

            $table->string('model_type')->nullable();

            $table->unsignedBigInteger('model_id')->nullable();

            $table->json('properties')->nullable();

            $table->timestamps();

            $table->index(['institution_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['module', 'action']);
            $table->index(['model_type', 'model_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};

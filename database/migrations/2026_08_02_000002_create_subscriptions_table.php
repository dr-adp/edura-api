<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('institution_id')
                ->constrained('institutions')
                ->cascadeOnDelete();

            $table->foreignId('subscription_plan_id')
                ->constrained('subscription_plans')
                ->restrictOnDelete();

            $table->enum('status', [
                'trial',
                'active',
                'suspended',
                'expired',
                'cancelled',
            ])->default('trial');

            $table->enum('billing_cycle', ['monthly', 'yearly'])->default('yearly');
            $table->dateTime('starts_at');
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('current_period_starts_at')->nullable();
            $table->dateTime('current_period_ends_at')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('external_reference')->nullable();
            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['institution_id', 'status']);
            $table->index(['subscription_plan_id', 'status']);
            $table->index(['status', 'current_period_ends_at']);
            $table->index('external_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

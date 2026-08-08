<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_plan_id')
                ->constrained('subscription_plans')
                ->cascadeOnDelete();

            $table->foreignId('feature_id')
                ->constrained('features')
                ->cascadeOnDelete();

            $table->boolean('enabled')->default(true);
            $table->json('value')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['subscription_plan_id', 'feature_id'],
                'plan_features_plan_feature_unique'
            );
            $table->index(['feature_id', 'enabled']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};

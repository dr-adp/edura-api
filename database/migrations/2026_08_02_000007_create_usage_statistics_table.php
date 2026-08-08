<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_statistics', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institution_id')
                ->constrained('institutions')
                ->cascadeOnDelete();

            $table->foreignId('subscription_id')
                ->nullable()
                ->constrained('subscriptions')
                ->nullOnDelete();

            $table->string('metric');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('used_value', 15, 4)->default(0);
            $table->decimal('limit_value', 15, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['institution_id', 'metric', 'period_start', 'period_end'],
                'usage_stats_institution_metric_period_unique'
            );
            $table->index(['institution_id', 'metric']);
            $table->index(['metric', 'period_start', 'period_end']);
            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_statistics');
    }
};

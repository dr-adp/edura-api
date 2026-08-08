<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('subscription_plans', 'trial_days')) {
                $table->unsignedInteger('trial_days')->default(0)->after('billing_cycle');
            }

            if (!Schema::hasColumn('subscription_plans', 'included_ai_credits')) {
                $table->decimal('included_ai_credits', 12, 4)->default(0)->after('storage_limit_mb');
            }

            if (!Schema::hasColumn('subscription_plans', 'api_request_limit')) {
                $table->unsignedInteger('api_request_limit')->nullable()->after('included_ai_credits');
            }

            if (!Schema::hasColumn('subscription_plans', 'limits')) {
                $table->json('limits')->nullable()->after('api_request_limit');
            }

            if (!Schema::hasColumn('subscription_plans', 'metadata')) {
                $table->json('metadata')->nullable()->after('description');
            }

            if (!Schema::hasColumn('subscription_plans', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('status');
            }
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->index(['status', 'sort_order'], 'subscription_plans_status_sort_index');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropIndex('subscription_plans_status_sort_index');
        });

        $columns = array_values(array_filter([
            'trial_days',
            'included_ai_credits',
            'api_request_limit',
            'limits',
            'metadata',
            'sort_order',
        ], fn (string $column): bool => Schema::hasColumn('subscription_plans', $column)));

        if ($columns !== []) {
            Schema::table('subscription_plans', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};

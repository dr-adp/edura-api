<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_ai_credit_grants', function (Blueprint $table) {
            $table->foreignId('subscription_id')
                ->primary()
                ->constrained('subscriptions')
                ->cascadeOnDelete();
        });

        DB::table('subscription_ai_credit_grants')->insertUsing(
            ['subscription_id'],
            DB::table('ai_credit_transactions as transactions')
                ->join(
                    'subscriptions',
                    'subscriptions.id',
                    '=',
                    'transactions.subscription_id'
                )
                ->select('transactions.subscription_id')
                ->whereColumn(
                    'transactions.institution_id',
                    'subscriptions.institution_id'
                )
                ->where('transactions.transaction_type', 'grant')
                ->where('transactions.source', 'subscription_included')
                ->groupBy('transactions.subscription_id')
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_ai_credit_grants');
    }
};

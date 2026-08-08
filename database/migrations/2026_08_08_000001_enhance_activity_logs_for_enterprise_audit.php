<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('auditable_type')->nullable()->after('model_id');
            $table->unsignedBigInteger('auditable_id')->nullable()->after('auditable_type');
            $table->json('old_values')->nullable()->after('properties');
            $table->json('new_values')->nullable()->after('old_values');
            $table->json('metadata')->nullable()->after('new_values');
            $table->string('request_id', 100)->nullable()->after('user_agent');

            $table->index('action', 'activity_logs_action_idx');
            $table->index('created_at', 'activity_logs_created_at_idx');
            $table->index(['auditable_type', 'auditable_id'], 'activity_logs_auditable_idx');
            $table->index(['institution_id', 'action', 'created_at'], 'activity_logs_inst_action_created_idx');
            $table->index(['institution_id', 'user_id', 'created_at'], 'activity_logs_inst_user_created_idx');
            $table->index(['institution_id', 'auditable_type', 'created_at'], 'activity_logs_inst_audit_type_created_idx');
            $table->index(['request_id'], 'activity_logs_request_id_idx');
        });

        DB::table('activity_logs')
            ->whereNull('auditable_type')
            ->whereNotNull('model_type')
            ->update([
                'auditable_type' => DB::raw('model_type'),
                'auditable_id' => DB::raw('model_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_request_id_idx');
            $table->dropIndex('activity_logs_inst_audit_type_created_idx');
            $table->dropIndex('activity_logs_inst_user_created_idx');
            $table->dropIndex('activity_logs_inst_action_created_idx');
            $table->dropIndex('activity_logs_auditable_idx');
            $table->dropIndex('activity_logs_created_at_idx');
            $table->dropIndex('activity_logs_action_idx');

            $table->dropColumn([
                'auditable_type',
                'auditable_id',
                'old_values',
                'new_values',
                'metadata',
                'request_id',
            ]);
        });
    }
};

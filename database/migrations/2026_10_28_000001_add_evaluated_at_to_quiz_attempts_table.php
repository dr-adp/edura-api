<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dateTime('evaluated_at')->nullable()->after('submitted_at');
        });

        DB::table('quiz_attempts')
            ->where('status', 'evaluated')
            ->whereNull('evaluated_at')
            ->update([
                'evaluated_at' => DB::raw('COALESCE(updated_at, submitted_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn('evaluated_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->uuid('certificate_uuid')
                ->nullable()
                ->unique()
                ->after('certificate_number');

            $table->string('verification_token')
                ->nullable()
                ->unique()
                ->after('certificate_uuid');

            $table->enum('verification_status', [
                'valid',
                'revoked',
                'expired'
            ])->default('valid')->after('status');
        });

        DB::table('certificates')->orderBy('id')->chunk(100, function ($certificates) {
            foreach ($certificates as $certificate) {
                DB::table('certificates')
                    ->where('id', $certificate->id)
                    ->update([
                        'certificate_uuid' => (string) Str::uuid(),
                        'verification_token' => strtoupper(Str::random(16)),
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn([
                'certificate_uuid',
                'verification_token',
                'verification_status',
            ]);
        });
    }
};

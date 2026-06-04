<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_settings', function (Blueprint $table) {

            $table->string('institution_seal')->nullable()->after('logo');

            $table->string('secondary_signature_image')
                ->nullable()
                ->after('signature_image');

            $table->string('secondary_signatory_name')
                ->nullable()
                ->after('authorized_person_designation');

            $table->string('secondary_signatory_designation')
                ->nullable()
                ->after('secondary_signatory_name');

            $table->string('certificate_background')
                ->nullable()
                ->after('institution_seal');

            $table->string('verification_url')
                ->nullable()
                ->after('certificate_background');

            $table->boolean('show_qr_code')
                ->default(true)
                ->after('verification_url');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_settings', function (Blueprint $table) {

            $table->dropColumn([
                'institution_seal',
                'secondary_signature_image',
                'secondary_signatory_name',
                'secondary_signatory_designation',
                'certificate_background',
                'verification_url',
                'show_qr_code',
            ]);
        });
    }
};

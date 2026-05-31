<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institution_id')
                ->constrained('institutions')
                ->cascadeOnDelete();

            $table->string('certificate_title')->default('Certificate of Completion');
            $table->string('certificate_subtitle')->nullable();

            $table->string('logo')->nullable();
            $table->string('signature_image')->nullable();

            $table->string('authorized_person_name')->nullable();
            $table->string('authorized_person_designation')->nullable();

            $table->text('footer_text')->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique('institution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_settings');
    }
};

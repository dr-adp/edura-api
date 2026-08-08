<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institution_id')
                ->constrained('institutions')
                ->cascadeOnDelete();

            $table->string('group');
            $table->string('key');
            $table->json('value')->nullable();
            $table->enum('value_type', [
                'string',
                'integer',
                'decimal',
                'boolean',
                'array',
                'json',
            ])->default('string');
            $table->boolean('is_public')->default(false);
            $table->boolean('is_encrypted')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['institution_id', 'group', 'key'],
                'institution_settings_institution_group_key_unique'
            );
            $table->index(['institution_id', 'group']);
            $table->index(['group', 'key']);
            $table->index(['is_public', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_settings');
    }
};

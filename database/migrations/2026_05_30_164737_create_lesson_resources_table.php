<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_resources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lesson_id')
                ->constrained('lessons')
                ->cascadeOnDelete();

            $table->string('title');

            $table->enum('resource_type', [
                'text',
                'pdf',
                'video',
                'image',
                'link',
                'document',
                'other'
            ])->default('text');

            $table->longText('content')->nullable();
            $table->string('file_path')->nullable();
            $table->string('external_url')->nullable();

            $table->integer('sort_order')->default(1);

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_resources');
    }
};

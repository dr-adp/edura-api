<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->enum('video_provider', [
                'local',
                'youtube',
                'vimeo',
                'bunny',
                'cloudflare',
                'external'
            ])->nullable()->after('resource_type');

            $table->integer('video_duration_minutes')->nullable()->after('external_url');
            $table->decimal('video_size_mb', 10, 2)->nullable()->after('video_duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->dropColumn([
                'video_provider',
                'video_duration_minutes',
                'video_size_mb',
            ]);
        });
    }
};

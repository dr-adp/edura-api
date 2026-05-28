<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('code')->unique();

            $table->decimal('price', 10, 2)->default(0);
            $table->enum('billing_cycle', ['monthly', 'yearly'])->default('yearly');

            $table->unsignedInteger('max_teachers')->default(1);
            $table->unsignedInteger('max_students')->default(50);
            $table->unsignedInteger('max_courses')->default(5);
            $table->unsignedInteger('storage_limit_mb')->default(1024);

            $table->boolean('allow_live_classes')->default(true);
            $table->boolean('allow_recorded_classes')->default(true);
            $table->boolean('allow_ai_reports')->default(false);
            $table->boolean('allow_hand_sign_module')->default(false);
            $table->boolean('allow_noticeboard')->default(true);
            $table->boolean('allow_notes_upload')->default(true);

            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};

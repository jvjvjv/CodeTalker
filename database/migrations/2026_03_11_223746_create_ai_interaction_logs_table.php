<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_interaction_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_system_id')->constrained('ai_systems')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('feature', 100);
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->string('model');
            $table->integer('duration_ms')->nullable();
            $table->string('status', 50);
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_interaction_logs');
    }
};

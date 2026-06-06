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
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(config('code-talker.user_model'), 'user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('ai_system_id')->constrained('ai_systems')->cascadeOnDelete();
            $table->string('feature', 100);
            $table->string('title')->nullable();
            $table->string('status', 50)->default('active');
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};

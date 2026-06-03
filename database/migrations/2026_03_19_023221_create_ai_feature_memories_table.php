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
        Schema::create('ai_feature_memories', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 50);
            $table->string('category', 50);
            $table->string('key', 255);
            $table->text('content');
            $table->unsignedTinyInteger('confidence')->default(50);
            $table->foreignId('source_conversation_id')
                ->nullable()
                ->constrained('ai_conversations')
                ->nullOnDelete();
            $table->timestamp('last_reinforced_at')->nullable();
            $table->unsignedInteger('times_reinforced')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['feature', 'is_active', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_feature_memories');
    }
};

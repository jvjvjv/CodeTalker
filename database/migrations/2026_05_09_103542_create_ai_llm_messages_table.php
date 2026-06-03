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
        Schema::create('ai_llm_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['request', 'response']); // Request TO LLM vs Response FROM LLM
            $table->string('turn_number'); // Sequential number within conversation (1, 2, 3...)
            $table->json('request_data')->nullable(); // Full API request payload sent to LLM provider
            $table->json('response_data')->nullable(); // Complete response from LLM provider
            $table->unsignedInteger('duration_ms')->nullable(); // Time taken for this turn in milliseconds
            $table->timestamp('created_at')->useCurrent();

            // Indexes for efficient querying of conversation history
            $table->index(['ai_conversation_id', 'turn_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_llm_messages');
    }
};
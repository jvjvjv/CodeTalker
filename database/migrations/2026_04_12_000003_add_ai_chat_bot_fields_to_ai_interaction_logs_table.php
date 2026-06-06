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
        Schema::table('ai_interaction_logs', function (Blueprint $table) {
            $table->foreignId('ai_chat_bot_id')->nullable()->after('ai_conversation_id')->constrained('ai_chat_bots')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_interaction_logs', function (Blueprint $table) {
            $table->dropForeign(['ai_chat_bot_id']);
            $table->dropColumn('ai_chat_bot_id');
        });
    }
};

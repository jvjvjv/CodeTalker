<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropForeign(['ai_chat_bot_id']);
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->renameColumn('ai_chat_bot_id', 'ai_persona_id');
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->foreign('ai_persona_id')->references('id')->on('ai_personas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropForeign(['ai_persona_id']);
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->renameColumn('ai_persona_id', 'ai_chat_bot_id');
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->foreign('ai_chat_bot_id')->references('id')->on('ai_personas')->nullOnDelete();
        });
    }
};

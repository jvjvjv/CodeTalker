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
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->foreignUuid('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreignId('ai_chat_bot_id')->nullable()->after('ai_system_id')->constrained('ai_chat_bots')->nullOnDelete();
            $table->string('visitor_name')->nullable()->after('title');
            $table->string('visitor_email')->nullable()->after('visitor_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropForeign(['ai_chat_bot_id']);
            $table->dropColumn(['ai_chat_bot_id', 'visitor_name', 'visitor_email']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->foreignUuid('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};

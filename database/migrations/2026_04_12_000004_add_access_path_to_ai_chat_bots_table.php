<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Jvjjvjv\CodeTalker\Models\AiChatBot;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_chat_bots', function (Blueprint $table) {
            $table->string('access_path')->default(AiChatBot::ACCESS_PATH_CHAT)->after('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_chat_bots', function (Blueprint $table) {
            $table->dropColumn('access_path');
        });
    }
};

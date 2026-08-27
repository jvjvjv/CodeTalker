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
        // Was AiChatBot::ACCESS_PATH_CHAT ('chat') — that model class no longer
        // exists (renamed to AiPersona), and a migration should not depend on
        // an application class whose name can change out from under it. The
        // literal value is identical; this is not a behavior change.
        Schema::table('ai_chat_bots', function (Blueprint $table) {
            $table->string('access_path')->default('chat')->after('slug');
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

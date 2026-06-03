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
        Schema::table('ai_chat_bots', function (Blueprint $table) {
            $table->boolean('tools_enabled')->default(false)->after('require_visitor_identity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_chat_bots', function (Blueprint $table) {
            $table->dropColumn('tools_enabled');
        });
    }
};

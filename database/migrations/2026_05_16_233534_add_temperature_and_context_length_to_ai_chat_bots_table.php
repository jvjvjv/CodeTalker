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
            $table->unsignedInteger('context_length')->nullable()->after('ai_system_id');
            $table->decimal('temperature', 3, 2)->nullable()->after('context_length');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_chat_bots', function (Blueprint $table) {
            $table->dropColumn(['context_length', 'temperature']);
        });
    }
};

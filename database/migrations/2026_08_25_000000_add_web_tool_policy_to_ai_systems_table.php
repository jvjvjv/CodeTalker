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
        Schema::table('ai_systems', function (Blueprint $table) {
            $table->text('web_tool_policy')->nullable()->after('allowed_tools');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_systems', function (Blueprint $table) {
            $table->dropColumn('web_tool_policy');
        });
    }
};

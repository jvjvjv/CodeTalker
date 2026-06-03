<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_systems', function (Blueprint $table) {
            // Null = thinking disabled (backward-compat default).
            // True = thinking explicitly enabled. False = explicitly disabled.
            $table->boolean('enable_thinking')->nullable()->after('supports_json_mode');
        });
    }

    public function down(): void
    {
        Schema::table('ai_systems', function (Blueprint $table) {
            $table->dropColumn('enable_thinking');
        });
    }
};

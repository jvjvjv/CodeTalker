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
            $table->unsignedInteger('context_length')->nullable()->after('max_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_systems', function (Blueprint $table) {
            $table->dropColumn('context_length');
        });
    }
};

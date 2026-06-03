<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_systems', function (Blueprint $table) {
            $table->dropColumn('system_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('ai_systems', function (Blueprint $table) {
            $table->text('system_prompt')->nullable()->after('system_prompt_id');
        });
    }
};

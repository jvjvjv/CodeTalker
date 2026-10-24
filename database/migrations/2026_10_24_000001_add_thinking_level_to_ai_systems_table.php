<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A string rather than a database enum, like `role`. The backfill keeps
     * each system's intent: thinking on becomes medium, off stays off, and an
     * unset flag leaves the level unset. `enable_thinking` stays in place so
     * the previous package version still reads the right value on rollback.
     */
    public function up(): void
    {
        Schema::table('ai_systems', function (Blueprint $table) {
            $table->string('thinking_level', 20)->nullable()->after('enable_thinking');
        });

        DB::table('ai_systems')->where('enable_thinking', true)->update(['thinking_level' => 'medium']);
        DB::table('ai_systems')->where('enable_thinking', false)->update(['thinking_level' => 'off']);
    }

    public function down(): void
    {
        Schema::table('ai_systems', function (Blueprint $table) {
            $table->dropColumn('thinking_level');
        });
    }
};

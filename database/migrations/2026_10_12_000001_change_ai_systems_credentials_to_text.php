<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `credentials` is cast `encrypted:array`, and an encrypted value is an
     * opaque string rather than a JSON document — a `json` column refuses it
     * on any database that validates JSON on write.
     */
    public function up(): void
    {
        Schema::table('ai_systems', function (Blueprint $table) {
            $table->text('credentials')->nullable()->change();
        });
    }

    /**
     * Deliberately a no-op. Once a row holds an encrypted value the column
     * cannot go back to `json` without discarding it, and a `text` column
     * stores everything the old schema could.
     */
    public function down(): void
    {
    }
};

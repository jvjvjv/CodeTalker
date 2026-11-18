<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The default prompts are seeded with explicit ids so AiSystemPromptIds
     * stays valid. Postgres does not advance a sequence for an explicit id,
     * so the first AiSystemPrompt::create() would collide on id 1. Running
     * as its own migration also repairs a host that seeded before this fix.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Raw SQL bypasses the query builder, so the connection's table prefix
        // has to be applied here. The quoted name also works as the text
        // argument to pg_get_serial_sequence(), which parses it as an
        // identifier.
        $table = DB::connection()->getQueryGrammar()->wrapTable('ai_system_prompts');

        DB::statement(
            "select setval(pg_get_serial_sequence('{$table}', 'id'), "
            . "coalesce((select max(id) from {$table}), 1))"
        );
    }

    public function down(): void
    {
    }
};

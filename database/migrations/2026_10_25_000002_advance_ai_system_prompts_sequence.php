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

        DB::statement(
            "select setval(pg_get_serial_sequence('ai_system_prompts', 'id'), "
            . 'coalesce((select max(id) from ai_system_prompts), 1))'
        );
    }

    public function down(): void
    {
    }
};

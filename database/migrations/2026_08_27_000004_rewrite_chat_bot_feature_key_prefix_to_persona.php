<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * AiChatBot::featureKey() changed its prefix from "chat-bot:" to
     * "persona:" as part of the AiChatBot -> AiPersona rename. Feature keys
     * are persisted verbatim in ai_system_feature_defaults.feature and
     * matched by exact string, so existing rows must be remapped or
     * AgentFactory::forFeature() would silently stop resolving a default
     * system for them.
     */
    public function up(): void
    {
        $this->remapPrefix('chat-bot:', 'persona:');
    }

    public function down(): void
    {
        $this->remapPrefix('persona:', 'chat-bot:');
    }

    private function remapPrefix(string $from, string $to): void
    {
        DB::table('ai_system_feature_defaults')
            ->where('feature', 'like', $from . '%')
            ->orderBy('id')
            ->each(function (object $row) use ($from, $to): void {
                $slug = substr((string) $row->feature, strlen($from));

                DB::table('ai_system_feature_defaults')
                    ->where('id', $row->id)
                    ->update(['feature' => $to . $slug]);
            });
    }
};

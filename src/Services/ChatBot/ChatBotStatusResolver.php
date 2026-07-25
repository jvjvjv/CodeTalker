<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot;

use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Services\AiModelReadinessService;

/**
 * Readiness status for every active bot, keyed by slug.
 *
 * Bots commonly share an AiSystem, and a readiness check can reach out to the
 * provider — so each system is only checked once per request.
 */
class ChatBotStatusResolver
{
    public function __construct(
        private AiModelReadinessService $modelReadiness,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function statusesBySlug(): array
    {
        $bots = AiChatBot::query()
            ->active()
            ->with('aiSystem')
            ->orderBy('name')
            ->get()
            ->values();

        $statusesBySystemId = [];
        $statusesByBotSlug = [];

        foreach ($bots as $bot) {
            if (!array_key_exists($bot->ai_system_id, $statusesBySystemId)) {
                $statusesBySystemId[$bot->ai_system_id] = $this->modelReadiness->statusForSystem($bot->aiSystem);
            }

            $statusesByBotSlug[$bot->slug] = $statusesBySystemId[$bot->ai_system_id];
        }

        return $statusesByBotSlug;
    }
}

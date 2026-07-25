<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot;

use Illuminate\Http\Request;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;

/**
 * Turns this browser's remembered conversation handles into the switcher list
 * the chat UI renders.
 *
 * Handles whose conversation has since been deleted are dropped rather than
 * rendered as broken entries.
 */
class ConversationHistoryPresenter
{
    public function __construct(
        private ConversationSessionStore $sessions,
    ) {
    }

    /**
     * @return array<int, array{handle: string, label: string, is_current: bool, is_stale: bool, updated_at: string, cost_usd: ?float}>
     */
    public function forBot(Request $request, AiChatBot $aiChatBot): array
    {
        $state = $this->sessions->state($request, $aiChatBot);
        $historyItems = collect($state['history']);

        if ($historyItems->isEmpty()) {
            return [];
        }

        $conversations = AiConversation::query()
            ->where('ai_chat_bot_id', $aiChatBot->id)
            ->whereIn('public_id', $historyItems->pluck('public_id')->all())
            ->orderByLastMessageAtDesc()
            ->get()
            ->keyBy('public_id');

        return $historyItems
            ->map(function (array $item) use ($conversations, $state): ?array {
                /** @var AiConversation|null $conversation */
                $conversation = $conversations->get($item['public_id']);

                if ($conversation === null) {
                    return null;
                }

                return [
                    'handle' => $item['handle'],
                    'label' => trim((string) ($conversation->title ?: 'New chat')),
                    'is_current' => ($state['current'] ?? null) === $conversation->public_id,
                    'is_stale' => $conversation->is_stale,
                    'updated_at' => $conversation->last_message_at?->diffForHumans()
                        ?? $conversation->updated_at?->diffForHumans()
                        ?? 'just now',
                    'cost_usd' => $conversation->usage_cost_usd !== null
                        ? (float) $conversation->usage_cost_usd
                        : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}

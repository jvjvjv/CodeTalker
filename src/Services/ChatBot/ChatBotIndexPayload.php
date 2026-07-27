<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot;

use Illuminate\Support\Collection;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;

/**
 * The props behind the `ai/ChatBotsIndex` page: every active bot, each with the
 * signed-in user's conversations. Guests see bots but no conversations.
 */
class ChatBotIndexPayload
{
    public function __construct(
        private ChatBotRouteUrls $urls,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(mixed $user): array
    {
        $bots = AiChatBot::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->values();

        $conversationsByBotId = $this->conversationsFor($user, $bots);

        return $bots->map(fn (AiChatBot $bot): array => [
            'slug' => $bot->slug,
            'name' => $bot->name,
            'description' => $bot->description,
            'new_chat_url' => $this->urls->for($bot, 'new'),
            'status_url' => $this->urls->for($bot, 'status'),
            'conversations' => collect($conversationsByBotId->get($bot->id, []))
                ->map(fn (AiConversation $conversation): array => [
                    'title' => trim((string) ($conversation->title ?: 'New chat')),
                    'updated_at' => $conversation->last_message_at?->toIso8601String()
                        ?? $conversation->updated_at?->toIso8601String(),
                    'updated_at_human' => $conversation->last_message_at?->diffForHumans()
                        ?? $conversation->updated_at?->diffForHumans()
                        ?? 'just now',
                    'is_stale' => $conversation->is_stale,
                ])
                ->values()
                ->all(),
        ])->all();
    }

    /**
     * @param Collection<int, AiChatBot> $bots
     * @return Collection<int, Collection<int, AiConversation>>
     */
    private function conversationsFor(mixed $user, Collection $bots): Collection
    {
        if ($user === null || $bots->isEmpty()) {
            return collect();
        }

        return AiConversation::query()
            ->where('user_id', $user->id)
            ->whereIn('ai_chat_bot_id', $bots->pluck('id')->all())
            ->orderByLastMessageAtDesc()
            ->get()
            ->groupBy('ai_chat_bot_id');
    }
}

<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot;

use Illuminate\Support\Collection;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;

/**
 * The queries a chat UI needs, minus the UI.
 *
 * These outlived the page payloads they used to belong to: the package no
 * longer renders pages, but a host building its own still needs the visible
 * transcript, a bot's lifetime cost, and a visitor's conversation list — none
 * of which exist anywhere else.
 */
class ChatBotPresenter
{
    /**
     * The visible transcript: everything except the system prompt, which is
     * instructions rather than something anyone said.
     *
     * @return array<int, array{role: string, content: ?string, reasoning_content: ?string, blocks: ?array}>
     */
    public function transcript(?AiConversation $conversation): array
    {
        if ($conversation === null) {
            return [];
        }

        return $conversation->messages()
            ->where('role', '!=', 'system')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (AiConversationMessage $message): array => [
                'role' => $message->role,
                'content' => $message->content,
                'reasoning_content' => $message->reasoning_content,
                'blocks' => $message->blocks,
            ])
            ->all();
    }

    /**
     * What every conversation with this bot has cost so far.
     */
    public function totalCostUsd(AiChatBot $bot): float
    {
        return (float) (AiConversation::query()
            ->where('ai_chat_bot_id', $bot->id)
            ->sum('usage_cost_usd') ?? 0);
    }

    /**
     * A user's conversations with the given bots, most recent first, grouped by
     * bot id.
     *
     * Anonymous visitors are not covered: without an account there is nothing
     * durable to key on, which is exactly why the package used to keep a cookie.
     * A host that wants that behaviour now owns it.
     *
     * @param Collection<int, AiChatBot> $bots
     * @return array<int, array<int, array{title: string, updated_at: ?string, updated_at_human: string, is_stale: bool}>>
     */
    public function conversationsFor(mixed $user, Collection $bots): array
    {
        if ($user === null || $bots->isEmpty()) {
            return [];
        }

        return AiConversation::query()
            ->where('user_id', $user->id)
            ->whereIn('ai_chat_bot_id', $bots->pluck('id')->all())
            ->orderByLastMessageAtDesc()
            ->get()
            ->groupBy('ai_chat_bot_id')
            ->map(static fn (Collection $conversations): array => $conversations
                ->map(static fn (AiConversation $conversation): array => [
                    'title' => trim((string) ($conversation->title ?: 'New chat')),
                    'updated_at' => $conversation->last_message_at?->toIso8601String()
                        ?? $conversation->updated_at?->toIso8601String(),
                    'updated_at_human' => $conversation->last_message_at?->diffForHumans()
                        ?? $conversation->updated_at?->diffForHumans()
                        ?? 'just now',
                    'is_stale' => $conversation->is_stale,
                ])
                ->values()
                ->all())
            ->all();
    }
}

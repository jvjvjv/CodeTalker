<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

use Jvjvjv\CodeTalker\Models\AiConversation;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\Message;

/**
 * Reads what a turn needs from a conversation's stored messages.
 *
 * History comes from the conversation store, so a replayed turn keeps its tool
 * calls, tool results, and attachments. The system prompt is read separately
 * because it reaches the agent as instructions rather than as part of the
 * transcript — the store deliberately excludes `system` rows from history.
 */
class ConversationHistory
{
    /**
     * laravel/ai's own default; enough turns that a conversation stays coherent
     * without unbounded context growth.
     */
    private const DEFAULT_LIMIT = 100;

    public function __construct(
        private ConversationStore $store,
    ) {
    }

    /**
     * The prompt the conversation was opened with. A conversation can accumulate
     * more than one system row over its life; the most recent one wins, matching
     * the behaviour of the transcript builder this replaced.
     */
    public function systemPromptFor(AiConversation $conversation): ?string
    {
        return $conversation->messages()
            ->where('role', 'system')
            ->orderByDesc('id')
            ->value('content');
    }

    /**
     * Prior turns, oldest first.
     *
     * Must be read *before* the incoming user message is persisted — that
     * message becomes the prompt, and would otherwise appear twice.
     *
     * @return array<int, Message>
     */
    public function historyFor(AiConversation $conversation, int $limit = self::DEFAULT_LIMIT): array
    {
        return $this->store
            ->getLatestConversationMessages((string) $conversation->id, $limit)
            ->all();
    }
}

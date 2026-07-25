<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

use Laravel\Ai\Messages\Message;

/**
 * A conversation's persisted messages split into the two things a provider
 * call needs: the system prompt, and the prior turns as agent messages.
 */
final class ConversationTranscript
{
    /**
     * @param array<int, Message> $history
     */
    public function __construct(
        public readonly ?string $systemPrompt,
        public readonly array $history,
    ) {
    }
}

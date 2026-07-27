<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;

/**
 * Numbers the turns of a conversation.
 *
 * Continuation attempts within a turn are logged as `N.1`, `N.2`, … so only the
 * whole-numbered rows count toward the next turn.
 */
class TurnSequence
{
    public function nextFor(AiConversation $conversation): int
    {
        $maxTurn = AiLlmMessage::query()
            ->where('ai_conversation_id', $conversation->id)
            ->max('turn_number');

        if ($maxTurn === null || !is_numeric($maxTurn)) {
            return 1;
        }

        return (int) $maxTurn + 1;
    }

    /**
     * The label for one attempt within a turn: the turn itself, then `N.1`, `N.2`, …
     */
    public function labelFor(int $turnNumber, int $attempt): string
    {
        return $attempt === 0 ? (string) $turnNumber : "{$turnNumber}.{$attempt}";
    }
}

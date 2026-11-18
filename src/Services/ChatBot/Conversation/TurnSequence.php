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
        // `turn_number` is a string column, so the database's MAX() compares
        // labels lexically and puts "9" after "10". Only whole-numbered labels
        // are read, and the maximum is taken in PHP so no driver-specific CAST
        // is needed.
        $maxTurn = AiLlmMessage::query()
            ->where('ai_conversation_id', $conversation->id)
            ->where('turn_number', 'not like', '%.%')
            ->distinct()
            ->pluck('turn_number')
            ->filter(static fn (mixed $label): bool => is_numeric($label))
            ->map(static fn (mixed $label): int => (int) $label)
            ->max();

        return $maxTurn === null ? 1 : $maxTurn + 1;
    }

    /**
     * The label for one attempt within a turn: the turn itself, then `N.1`, `N.2`, …
     */
    public function labelFor(int $turnNumber, int $attempt): string
    {
        return $attempt === 0 ? (string) $turnNumber : "{$turnNumber}.{$attempt}";
    }
}

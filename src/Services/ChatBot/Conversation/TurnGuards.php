<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

use Closure;

/**
 * The two conditions that can cut a turn short: the browser hanging up, and a
 * single provider request running past its wall-clock budget.
 *
 * Both are supplied as closures rather than called directly, because
 * AiPersonaConversationService exposes them as overridable hooks so tests can
 * drive them deterministically — the override has to be what the loop consults.
 */
final class TurnGuards
{
    /**
     * @param Closure(float): float $elapsedSeconds  seconds since a given start time
     * @param Closure(): bool $clientAborted
     */
    public function __construct(
        private Closure $elapsedSeconds,
        private Closure $clientAborted,
    ) {
    }

    public function elapsedSince(float $startedAt): float
    {
        return ($this->elapsedSeconds)($startedAt);
    }

    public function clientAborted(): bool
    {
        return ($this->clientAborted)();
    }
}

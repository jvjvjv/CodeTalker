<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

/**
 * How a turn ended, once its streaming loop is done.
 *
 * A turn that was cut short still produced content worth keeping, so this
 * reports the reason rather than throwing.
 */
final class TurnOutcome
{
    /**
     * @param array<int, array<string, mixed>> $toolCalls tool calls made across
     *        the whole turn, in the shape the conversation store rehydrates
     * @param array<int, array<string, mixed>> $toolResults what those calls returned
     */
    public function __construct(
        public readonly bool $clientAborted = false,
        public readonly bool $maxDurationExceeded = false,
        public readonly ?string $maxDurationMessage = null,
        public readonly int $durationMs = 0,
        public readonly array $toolCalls = [],
        public readonly array $toolResults = [],
    ) {
    }
}

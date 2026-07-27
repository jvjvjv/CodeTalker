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
    public function __construct(
        public readonly bool $clientAborted = false,
        public readonly bool $maxDurationExceeded = false,
        public readonly ?string $maxDurationMessage = null,
        public readonly int $durationMs = 0,
    ) {
    }
}

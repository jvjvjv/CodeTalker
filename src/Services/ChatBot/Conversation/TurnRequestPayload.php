<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

/**
 * The provider request payload currently in flight for a turn.
 *
 * Every continuation attempt rebuilds it, and if the turn breaks the failure is
 * logged against whichever attempt was running — so the runner and its caller
 * read it from one place rather than each keeping their own copy.
 */
final class TurnRequestPayload
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private array $payload,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function record(array $payload): void
    {
        $this->payload = $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function latest(): array
    {
        return $this->payload;
    }
}

<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi\Streaming;

use Laravel\Ai\Streaming\Events\StreamEvent;

/**
 * A tick emitted while the provider is silent.
 *
 * It carries no model output and never reaches the transcript or the logs. It
 * exists so something travels the stream during a long silent gap: the browser
 * gets a write (which is the only way PHP ever flips connection_aborted()), and
 * the turn's guards get an opportunity to run.
 */
class Heartbeat extends StreamEvent
{
    public function __construct(
        public string $id,
        public int $timestamp,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'heartbeat',
            'timestamp' => $this->timestamp,
        ];
    }
}

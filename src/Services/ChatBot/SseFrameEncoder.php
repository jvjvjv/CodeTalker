<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot;

use Generator;

/**
 * Encodes a turn's events as server-sent events.
 *
 * The package no longer owns an HTTP endpoint, but the wire format it used to
 * emit is documented, typed, and consumed by the published stream client. This
 * keeps it available to a host that wants it, without the turn itself knowing
 * anything about transports.
 */
class SseFrameEncoder
{
    /**
     * @param iterable<int, array<string, mixed>> $events
     * @return Generator<int, string>
     */
    public function encode(iterable $events): Generator
    {
        $failed = false;

        foreach ($events as $event) {
            // An error event is terminal on its own — nothing follows it, and
            // the stream is not sentinel-terminated. Consumers rely on this to
            // tell a failed turn from a finished one.
            $failed = ($event['type'] ?? null) === 'error';

            yield 'data: ' . json_encode($event) . "\n\n";
        }

        if (! $failed) {
            yield "data: [DONE]\n\n";
        }
    }
}

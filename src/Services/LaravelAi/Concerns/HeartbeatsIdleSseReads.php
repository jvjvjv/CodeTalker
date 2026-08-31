<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi\Concerns;

use Generator;
use Illuminate\Support\Str;
use Jvjvjv\CodeTalker\Services\LaravelAi\Streaming\Heartbeat;
use Jvjvjv\CodeTalker\Services\RawExchange\TeeingStream;

/**
 * Bounds laravel/ai's blocking SSE read so a silent provider does not mean a
 * silent socket.
 *
 * ParsesServerSentEvents::readLine() reads a byte at a time and blocks until
 * the next one arrives. While it blocks, the whole turn is suspended inside it
 * — a heartbeat cannot be yielded from the runner, the service, or the host's
 * controller, because none of them are running. This is the only seam.
 *
 * The partial-line buffer is the load-bearing detail. readLine() treats an
 * empty read as end-of-line, so a naive timeout would hand the parser half a
 * frame (`data: {"cho`), which starts with `data:`, fails json_decode
 * silently, and leaves its remainder to be dropped as a line with no `data:`
 * prefix. The frame would be lost. Here the buffer survives the idle window.
 */
trait HeartbeatsIdleSseReads
{
    /**
     * Empty reads that are neither a timeout nor EOF before giving up. A well
     * behaved stream reports one of the three; this only stops a misbehaving
     * wrapper from spinning forever.
     */
    private const MAX_EMPTY_READS = 100;

    /**
     * @return Generator<int, array<string, mixed>|Heartbeat>
     */
    protected function parseServerSentEvents($streamBody): Generator
    {
        $seconds = (int) config('code-talker.conversations.heartbeat_seconds', 5);

        // Checked before detaching, because detach() cannot be undone: a body
        // with no resource behind it (a PumpStream, a host's custom handler)
        // must reach the parent parser with its body intact.
        if ($seconds <= 0 || ! is_string($streamBody->getMetadata('stream_type'))) {
            yield from parent::parseServerSentEvents($streamBody);

            return;
        }

        // Raw-exchange capture tees every byte the parser reads. Detaching
        // takes the resource out from under the tee, so the reader below
        // feeds it the bytes itself — otherwise enabling heartbeats would
        // silently blank ai_provider_exchanges.raw_response.
        $tee = $streamBody instanceof TeeingStream ? $streamBody : null;

        $resource = $streamBody->detach();

        if (! is_resource($resource)) {
            return;
        }

        try {
            yield from $this->readSseWithHeartbeats($resource, $seconds, $tee);
        } finally {
            // Nothing else holds it once detached.
            fclose($resource);
        }
    }

    /**
     * @param resource $resource
     * @return Generator<int, array<string, mixed>|Heartbeat>
     */
    private function readSseWithHeartbeats($resource, int $seconds, ?TeeingStream $tee = null): Generator
    {
        stream_set_timeout($resource, $seconds);

        $buffer = '';
        $emptyReads = 0;

        while (true) {
            $byte = fread($resource, 1);

            if ($byte === false || $byte === '') {
                // A timed-out read surfaces as false on some platforms and as
                // '' on others, so both are checked against timed_out — and
                // before feof(), because a socket can report EOF after a read
                // timeout. Treating either as the end would turn every silent
                // gap into a truncated turn.
                if (stream_get_meta_data($resource)['timed_out'] ?? false) {
                    $emptyReads = 0;

                    yield new Heartbeat(strtolower((string) Str::uuid7()), time());

                    continue;
                }

                if ($byte === false || feof($resource)) {
                    return;
                }

                if (++$emptyReads >= self::MAX_EMPTY_READS) {
                    return;
                }

                continue;
            }

            $emptyReads = 0;
            $buffer .= $byte;
            $tee?->record($byte);

            if ($byte !== "\n") {
                continue;
            }

            $line = trim($buffer);
            $buffer = '';

            if ($line === '' || ! str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, 5));

            if ($data === '[DONE]') {
                return;
            }

            $decoded = json_decode($data, true);

            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                yield $decoded;
            }
        }
    }
}

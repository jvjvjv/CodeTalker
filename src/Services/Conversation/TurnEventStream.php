<?php

namespace Jvjvjv\CodeTalker\Services\Conversation;

use Generator;
use Jvjvjv\CodeTalker\Models\AiTurnEvent;
use Jvjvjv\CodeTalker\Models\AiTurnRun;

/**
 * The read side of a detached turn: replays a run's events from any point and
 * follows it live until it ends.
 *
 * Reading is also how a run stays alive. Each pass stamps last_polled_at, and a
 * run nobody stamps is abandoned — which is what closing a tab now means, since
 * connection_aborted() tells a queue worker nothing.
 */
class TurnEventStream
{
    public function __construct(
        private TurnRunStore $store,
    ) {
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(AiTurnRun $run, int $after = 0): Generator
    {
        $pollMicroseconds = max(1, (int) config('code-talker.turns.poll_interval_ms', 250)) * 1000;
        $heartbeatSeconds = (int) config('code-talker.conversations.heartbeat_seconds', 5);
        $maxSeconds = (int) config('code-talker.turns.max_stream_seconds', 900);

        $startedAt = microtime(true);
        $lastEmittedAt = $startedAt;

        while (true) {
            $this->store->touchPoll($run);

            $events = $this->store->eventsAfter($run, $after);

            if ($events->isNotEmpty()) {
                foreach ($events as $event) {
                    /** @var AiTurnEvent $event */
                    $after = $event->sequence;
                    $lastEmittedAt = microtime(true);

                    yield $event->payload + ['_seq' => $event->sequence];
                }

                continue;
            }

            // Nothing new. Read the status only now, and drain before stopping:
            // the job appends its last event and *then* marks the run finished,
            // so checking status first would drop that event. The drain pages
            // until it comes back empty — eventsAfter() caps each read, and a
            // backlog larger than one page must not be truncated.
            if ($run->fresh()?->status->isTerminal() ?? true) {
                do {
                    $drained = $this->store->eventsAfter($run, $after);

                    foreach ($drained as $event) {
                        /** @var AiTurnEvent $event */
                        $after = $event->sequence;

                        yield $event->payload + ['_seq' => $event->sequence];
                    }
                } while ($drained->isNotEmpty());

                return;
            }

            if ($maxSeconds > 0 && microtime(true) - $startedAt > $maxSeconds) {
                yield [
                    'type' => 'error',
                    'message' => "The turn exceeded the maximum stream duration of {$maxSeconds}s.",
                    'reason' => 'max_stream_duration',
                ];

                return;
            }

            if ($heartbeatSeconds > 0 && microtime(true) - $lastEmittedAt >= $heartbeatSeconds) {
                $lastEmittedAt = microtime(true);

                // Provider-agnostic, unlike the gateway's own heartbeat: this
                // one fires for every provider, because it is measured against
                // the store rather than a socket.
                yield ['type' => 'heartbeat'];
            }

            usleep($pollMicroseconds);
        }
    }
}

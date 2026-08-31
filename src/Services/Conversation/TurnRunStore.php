<?php

namespace Jvjvjv\CodeTalker\Services\Conversation;

use Illuminate\Support\Collection;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiTurnEvent;
use Jvjvjv\CodeTalker\Models\AiTurnRun;

/**
 * The write side of a detached turn.
 *
 * One instance belongs to one run, for the life of the job that drives it —
 * which is what lets the sequence counter and the stop check live in memory
 * rather than in a query per token.
 */
class TurnRunStore
{
    private int $sequence = 0;

    private ?bool $cachedShouldStop = null;

    private float $shouldStopCheckedAt = 0.0;

    /**
     * $stopCheckInterval — seconds between stop checks. shouldStop() is
     * consulted on every stream event, so an unthrottled read would put a
     * database round-trip inside the token loop for no benefit — nothing
     * decides to cancel a turn faster than this anyway.
     */
    public function __construct(private float $stopCheckInterval = 2.0)
    {
    }

    public function open(AiConversation $conversation, string $message): AiTurnRun
    {
        return AiTurnRun::create([
            'ai_conversation_id' => $conversation->id,
            'status' => AiTurnRunStatus::Queued,
            'prompt' => $message,
        ]);
    }

    public function markRunning(AiTurnRun $run): void
    {
        $run->forceFill([
            'status' => AiTurnRunStatus::Running,
            'started_at' => now(),
        ])->save();

        $this->sequence = (int) $run->events()->max('sequence');
    }

    /**
     * @param array<string, mixed> $event
     */
    public function append(AiTurnRun $run, array $event): int
    {
        $sequence = ++$this->sequence;

        AiTurnEvent::create([
            'ai_turn_run_id' => $run->id,
            'sequence' => $sequence,
            'payload' => $event,
        ]);

        return $sequence;
    }

    public function finish(AiTurnRun $run, AiTurnRunStatus $status, ?string $error = null): void
    {
        $run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'error_message' => $error,
        ])->save();
    }

    /**
     * @return Collection<int, AiTurnEvent>
     */
    public function eventsAfter(AiTurnRun $run, int $sequence, int $limit = 200): Collection
    {
        return AiTurnEvent::query()
            ->where('ai_turn_run_id', $run->id)
            ->where('sequence', '>', $sequence)
            ->orderBy('sequence')
            ->limit($limit)
            ->get();
    }

    public function touchPoll(AiTurnRun $run): void
    {
        AiTurnRun::query()->whereKey($run->id)->update(['last_polled_at' => now()]);
    }

    public function requestCancel(AiTurnRun $run): void
    {
        AiTurnRun::query()->whereKey($run->id)->update(['cancel_requested_at' => now()]);

        $this->cachedShouldStop = null;
    }

    /**
     * Whether the turn should stop generating: someone cancelled it, or nobody
     * is reading it any more.
     */
    public function shouldStop(AiTurnRun $run): bool
    {
        $now = microtime(true);

        if ($this->cachedShouldStop !== null && $now - $this->shouldStopCheckedAt < $this->stopCheckInterval) {
            return $this->cachedShouldStop;
        }

        $this->shouldStopCheckedAt = $now;

        return $this->cachedShouldStop = $this->readShouldStop($run);
    }

    /**
     * Which terminal status a stopped run earned.
     */
    public function stopStatusFor(AiTurnRun $run): AiTurnRunStatus
    {
        return $run->fresh()?->cancel_requested_at !== null
            ? AiTurnRunStatus::Cancelled
            : AiTurnRunStatus::Abandoned;
    }

    private function readShouldStop(AiTurnRun $run): bool
    {
        $fresh = $run->fresh();

        if ($fresh === null || $fresh->cancel_requested_at !== null) {
            return true;
        }

        $seconds = (int) config('code-talker.turns.abandon_after_seconds', 30);

        if ($seconds <= 0) {
            return false;
        }

        // Measured from created_at while nothing has polled yet: a run
        // dispatched a moment ago has no reader by definition, and killing it
        // before its reader connects would make the feature unusable.
        $since = $fresh->last_polled_at ?? $fresh->created_at;

        return $since !== null && $since->diffInSeconds(now()) > $seconds;
    }
}

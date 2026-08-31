<?php

namespace Jvjvjv\CodeTalker\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Models\AiTurnRun;
use Jvjvjv\CodeTalker\Services\AiPersonaConversationService;
use Jvjvjv\CodeTalker\Services\Conversation\TurnRunStore;
use Throwable;

/**
 * Runs one conversation turn detached from any HTTP connection.
 *
 * The turn logic is not duplicated here: this drives the same
 * continueConversation() the synchronous path uses and records what it yields.
 * What changes is only who is listening — and therefore how the turn learns
 * that nobody is.
 *
 * The job runs exactly once ($tries = 1): TurnRunStore assigns event sequences
 * from an in-memory counter, which is safe only while a single worker writes a
 * given run. If a job outran the queue connection's retry_after, the queue
 * would re-reserve it and a second worker would start writing the same run —
 * interleaved sequences caught only by the unique (ai_turn_run_id, sequence)
 * index throwing, with the losing worker able to leave the run stuck Running.
 * A re-reserved job is therefore failed rather than run a second time, which
 * means a host whose turns run longer than the queue connection's retry_after
 * must raise retry_after above their longest expected turn.
 */
class RunConversationTurnJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    /**
     * The run's id rather than the model: a queued payload stays small, and the
     * job reads current state rather than a snapshot taken at dispatch.
     */
    public function __construct(public int $turnRunId)
    {
        $this->onQueue(config('code-talker.turns.queue') ?: null);
    }

    public function handle(AiPersonaConversationService $chat, TurnRunStore $store): void
    {
        $run = AiTurnRun::find($this->turnRunId);

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        $store->markRunning($run);

        try {
            $events = $chat
                ->usingCancellationCheck(fn (): bool => $store->shouldStop($run))
                ->continueConversation($run->conversation, $run->prompt);

            foreach ($events as $event) {
                $store->append($run, $event);
            }
        } catch (Throwable $exception) {
            $store->finish($run, AiTurnRunStatus::Failed, $exception->getMessage());

            throw $exception;
        }

        $store->finish(
            $run,
            $store->shouldStop($run) ? $store->stopStatusFor($run) : AiTurnRunStatus::Completed,
        );
    }

    /**
     * A worker that dies leaves a reader polling forever unless the run is
     * closed out here.
     */
    public function failed(?Throwable $exception): void
    {
        $run = AiTurnRun::find($this->turnRunId);

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        app(TurnRunStore::class)->finish(
            $run,
            AiTurnRunStatus::Failed,
            $exception?->getMessage(),
        );
    }
}

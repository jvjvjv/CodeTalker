<?php

namespace Jvjvjv\CodeTalker\Console\Commands;

use Illuminate\Console\Command;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Models\AiTurnEvent;
use Jvjvjv\CodeTalker\Models\AiTurnRun;

class PruneTurnEventsCommand extends Command
{
    // Paged so a first sweep over a large backlog never binds an unbounded id
    // list into one delete (SQLite variable cap, MySQL max_allowed_packet).
    private const PAGE_SIZE = 1000;

    protected $signature = 'ai:prune-turn-events';

    protected $description = 'Delete finished turn runs and their events past the retention window';

    public function handle(): int
    {
        $days = (int) config('code-talker.turns.retention_days', 7);

        if ($days <= 0) {
            $this->info('Turn event retention is disabled; nothing pruned.');

            return self::SUCCESS;
        }

        $terminal = array_values(array_map(
            static fn (AiTurnRunStatus $status): string => $status->value,
            array_filter(AiTurnRunStatus::cases(), static fn (AiTurnRunStatus $s): bool => $s->isTerminal()),
        ));

        $cutoff = now()->subDays($days);
        $pruned = 0;

        do {
            // Only finished runs: a turn still generating is not garbage,
            // however long it has been going.
            $runIds = AiTurnRun::query()
                ->whereIn('status', $terminal)
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::PAGE_SIZE)
                ->pluck('id');

            if ($runIds->isEmpty()) {
                break;
            }

            // Events before runs: a failure between the two leaves runs whose
            // events are gone, and the next sweep re-selects and finishes them.
            AiTurnEvent::query()->whereIn('ai_turn_run_id', $runIds)->delete();
            AiTurnRun::query()->whereIn('id', $runIds)->delete();

            $pruned += $runIds->count();
        } while ($runIds->count() === self::PAGE_SIZE);

        if ($pruned === 0) {
            $this->info('No turn runs past retention.');

            return self::SUCCESS;
        }

        $this->info("Pruned {$pruned} turn run(s).");

        return self::SUCCESS;
    }
}

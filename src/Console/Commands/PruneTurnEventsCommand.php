<?php

namespace Jvjvjv\CodeTalker\Console\Commands;

use Illuminate\Console\Command;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Models\AiTurnEvent;
use Jvjvjv\CodeTalker\Models\AiTurnRun;

class PruneTurnEventsCommand extends Command
{
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

        // Only finished runs: a turn still generating is not garbage, however
        // long it has been going.
        $runIds = AiTurnRun::query()
            ->whereIn('status', $terminal)
            ->where('created_at', '<', now()->subDays($days))
            ->pluck('id');

        if ($runIds->isEmpty()) {
            $this->info('No turn runs past retention.');

            return self::SUCCESS;
        }

        AiTurnEvent::query()->whereIn('ai_turn_run_id', $runIds)->delete();
        AiTurnRun::query()->whereIn('id', $runIds)->delete();

        $this->info("Pruned {$runIds->count()} turn run(s).");

        return self::SUCCESS;
    }
}

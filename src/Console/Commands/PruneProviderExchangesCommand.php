<?php

namespace Jvjvjv\CodeTalker\Console\Commands;

use Illuminate\Console\Command;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;

class PruneProviderExchangesCommand extends Command
{
    protected $signature = 'ai:prune-provider-exchanges
        {--days= : Override the retention window in days}
        {--dry-run : Report how many rows would be deleted without deleting}';

    protected $description = 'Delete captured provider exchange rows older than the retention window.';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? max((int) $this->option('days'), 0)
            : (int) config('code-talker.raw_exchanges.retention_days', 14);

        $cutoff = now()->subDays($days);

        $query = AiProviderExchange::query()->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("Would delete {$query->count()} provider exchange(s) older than {$days} day(s).");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Deleted {$deleted} provider exchange(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}

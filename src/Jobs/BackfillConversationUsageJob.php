<?php

namespace Jvjvjv\CodeTalker\Jobs;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Queue\Queueable;

class BackfillConversationUsageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public bool $all = false,
        public int $chunk = 200,
    ) {
    }

    public function handle(): void
    {
        $lock = Cache::lock('ai:backfill-conversation-usage:job', 3600);

        if (!$lock->get()) {
            return;
        }

        try {
            Artisan::call('ai:backfill-conversation-usage', [
                '--all' => $this->all,
                '--chunk' => max($this->chunk, 1),
            ]);
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function releaseLock(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (\Throwable) {
        }
    }
}

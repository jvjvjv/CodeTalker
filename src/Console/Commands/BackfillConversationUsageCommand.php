<?php

namespace Jvjvjv\CodeTalker\Console\Commands;

use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Services\ConversationUsageService;
use Illuminate\Console\Command;

class BackfillConversationUsageCommand extends Command
{
    protected $signature = 'ai:backfill-conversation-usage
        {--all : Recompute usage for all conversations}
        {--chunk=200 : Number of conversations to process per batch}';

    protected $description = 'Backfill token and estimated cost usage for historical AI conversations.';

    public function __construct(private ConversationUsageService $conversationUsageService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $chunkSize = max((int) $this->option('chunk'), 1);
        $recomputeAll = (bool) $this->option('all');

        $query = AiConversation::query()->orderBy('id');

        if (!$recomputeAll) {
            $query->whereNull('usage_total_tokens');
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No conversations matched the backfill criteria.');

            return self::SUCCESS;
        }

        $this->info('Backfilling usage for ' . $total . ' conversation(s)...');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;

        $query->chunkById($chunkSize, function ($conversations) use (&$updated, $bar): void {
            foreach ($conversations as $conversation) {
                if (!$conversation instanceof AiConversation) {
                    continue;
                }

                if ($this->conversationUsageService->syncConversation($conversation)) {
                    $updated++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info('Backfill complete.');
        $this->line('Updated: ' . $updated);
        $this->line('Unchanged: ' . ($total - $updated));

        return self::SUCCESS;
    }
}

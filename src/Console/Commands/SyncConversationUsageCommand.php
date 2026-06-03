<?php

namespace Jvjvjv\CodeTalker\Console\Commands;

use Jvjvjv\CodeTalker\Enums\AiConversationStatus;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Services\ConversationUsageService;
use Illuminate\Console\Command;

class SyncConversationUsageCommand extends Command
{
    protected $signature = 'ai:sync-conversation-usage
        {--minutes=10 : Lookback window in minutes for active chats}
        {--limit=200 : Maximum active conversations to process}
        {--conversation-id= : Sync only one conversation}';

    protected $description = 'Sync token and estimated cost usage for active AI conversations.';

    public function __construct(private ConversationUsageService $conversationUsageService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $singleConversationId = $this->option('conversation-id');
        $processed = 0;
        $changed = 0;

        if ($singleConversationId !== null) {
            $conversation = AiConversation::query()->find($singleConversationId);

            if (!$conversation) {
                $this->error('Conversation not found.');

                return self::FAILURE;
            }

            if ($this->conversationUsageService->syncConversation($conversation)) {
                $changed++;
            }

            $this->info('Synced conversation usage for ID ' . $conversation->id . '.');

            return self::SUCCESS;
        }

        $minutes = max((int) $this->option('minutes'), 1);
        $limit = max((int) $this->option('limit'), 1);
        $activityCutoff = now()->subMinutes($minutes);

        $conversations = AiConversation::query()
            ->where('status', AiConversationStatus::Active->value)
            ->whereHas('messages', function ($messageQuery) use ($activityCutoff): void {
                $messageQuery
                    ->where('role', '!=', 'system')
                    ->where('created_at', '>=', $activityCutoff);
            })
            ->orderByLastMessageAtDesc()
            ->limit($limit)
            ->get();

        foreach ($conversations as $conversation) {
            if (!$conversation instanceof AiConversation) {
                continue;
            }

            $processed++;

            if ($this->conversationUsageService->syncConversation($conversation)) {
                $changed++;
            }
        }

        $this->info('Conversation usage sync complete.');
        $this->line('Processed: ' . $processed);
        $this->line('Changed: ' . $changed);

        return self::SUCCESS;
    }
}

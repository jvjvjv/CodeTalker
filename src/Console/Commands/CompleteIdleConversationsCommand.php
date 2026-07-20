<?php

namespace Jvjvjv\CodeTalker\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Jvjvjv\CodeTalker\Enums\AiConversationStatus;
use Jvjvjv\CodeTalker\Models\AiConversation;

/**
 * Marks Active conversations Completed once they have gone quiet.
 *
 * Nothing in the chat request path can know that a conversation is over — the
 * browser simply stops sending messages — so completion is inferred from
 * inactivity here. Flipping the status is what fires AiConversationObserver,
 * which dispatches ProcessAiMemoryJob exactly once per conversation.
 */
class CompleteIdleConversationsCommand extends Command
{
    protected $signature = 'ai:complete-idle-conversations
        {--minutes= : Override the idle window in minutes}
        {--dry-run : Report how many conversations would be completed without changing them}';

    protected $description = 'Mark Active conversations Completed after a period of inactivity, triggering memory extraction.';

    public function handle(): int
    {
        $minutes = $this->option('minutes') !== null
            ? max((int) $this->option('minutes'), 0)
            : (int) config('code-talker.conversations.idle_timeout_minutes', 30);

        $cutoff = now()->subMinutes($minutes);

        $query = AiConversation::query()
            ->where('status', AiConversationStatus::Active->value)
            // Must have said something. Empty conversations carry nothing worth
            // extracting, and completing them would burn a provider call on an
            // empty transcript.
            ->whereHas('messages', fn (Builder $q) => $q->where('role', '!=', 'system'))
            // ...and nothing recent, or it is still in progress.
            ->whereDoesntHave('messages', fn (Builder $q) => $q
                ->where('role', '!=', 'system')
                ->where('created_at', '>=', $cutoff));

        if ($this->option('dry-run')) {
            $this->info("Would complete {$query->count()} idle conversation(s) with no activity for {$minutes} minute(s).");

            return self::SUCCESS;
        }

        $completed = 0;

        // Updated one at a time so the model observer fires per conversation;
        // a mass update() would bypass it and skip memory extraction entirely.
        $query->each(function (AiConversation $conversation) use (&$completed): void {
            $conversation->update(['status' => AiConversationStatus::Completed]);
            $completed++;
        });

        $this->info("Completed {$completed} idle conversation(s) with no activity for {$minutes} minute(s).");

        return self::SUCCESS;
    }
}

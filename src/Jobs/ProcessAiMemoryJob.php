<?php

namespace Jvjvjv\CodeTalker\Jobs;

use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Services\AiMemoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAiMemoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public AiConversation $conversation,
        protected ?string $userId = null,
        protected ?string $visitorEmail = null,
    ) {
    }

    public function handle(AiMemoryService $memoryService): void
    {
        $userId = $this->userId ?? $this->conversation->user_id;
        $visitorEmail = $this->visitorEmail ?? $this->conversation->visitor_email;

        $memoryService->processCompletedConversation(
            $this->conversation,
            $userId,
            $visitorEmail
        );
    }
}

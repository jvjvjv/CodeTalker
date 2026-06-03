<?php

namespace Jvjvjv\CodeTalker\Observers;

use Jvjvjv\CodeTalker\Enums\AiConversationStatus;
use Jvjvjv\CodeTalker\Jobs\ProcessAiMemoryJob;
use Jvjvjv\CodeTalker\Models\AiConversation;

class AiConversationObserver
{
    public function updated(AiConversation $aiConversation): void
    {
        if ($aiConversation->wasChanged('status')
            && $aiConversation->status === AiConversationStatus::Completed) {
            dispatch(new ProcessAiMemoryJob($aiConversation));
        }
    }
}

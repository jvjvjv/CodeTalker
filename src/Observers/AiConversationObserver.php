<?php

namespace Jvjvjv\CodeTalker\Observers;

use Jvjvjv\CodeTalker\Enums\AiConversationStatus;
use Jvjvjv\CodeTalker\Jobs\ProcessAiMemoryJob;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiFeatureMemory;

class AiConversationObserver
{
    public function updated(AiConversation $aiConversation): void
    {
        if ($aiConversation->wasChanged('status')
            && $aiConversation->status === AiConversationStatus::Completed
            && $this->shouldExtractMemories($aiConversation)) {
            dispatch(new ProcessAiMemoryJob($aiConversation));
        }
    }

    /**
     * Memories belong to someone. An anonymous conversation has no one to scope
     * them to, and an operator run is not a person talking — the job would only
     * be skipped once it ran, so don't queue it.
     */
    private function shouldExtractMemories(AiConversation $aiConversation): bool
    {
        return $aiConversation->ai_operator_id === null
            && AiFeatureMemory::hasIdentity($aiConversation->user_id, $aiConversation->visitor_email);
    }
}

<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

use Illuminate\Support\Str;

/**
 * Names a conversation after whatever the visitor opened it with.
 */
class ConversationTitle
{
    public function fromUserMessage(string $userMessage): string
    {
        $normalized = Str::of(strip_tags($userMessage))
            ->squish()
            ->trim();

        if ($normalized->isEmpty()) {
            return 'New chat';
        }

        return Str::limit($normalized->toString(), 80, '...');
    }
}

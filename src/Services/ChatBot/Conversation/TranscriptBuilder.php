<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;

/**
 * Reads a conversation's stored messages into the transcript a turn is built on.
 */
class TranscriptBuilder
{
    /**
     * @param AiConversationMessage $currentUserMessage the message being answered,
     *        which becomes the prompt rather than part of the history
     */
    public function build(AiConversation $conversation, AiConversationMessage $currentUserMessage): ConversationTranscript
    {
        $systemPrompt = null;
        $history = [];

        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($messages as $message) {
            if ($message->role === 'system') {
                $systemPrompt = $message->content;

                continue;
            }

            if ($message->id === $currentUserMessage->id) {
                continue;
            }

            $content = (string) $message->content;

            if ($message->role === 'assistant') {
                // Providers reject empty assistant turns, which a cut-off
                // reasoning-only turn can leave behind.
                if (trim($content) === '') {
                    continue;
                }

                $history[] = new AssistantMessage($content);

                continue;
            }

            $history[] = new UserMessage($content);
        }

        return new ConversationTranscript($systemPrompt, $history);
    }
}

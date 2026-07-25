<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

use Laravel\Ai\Messages\Message;

/**
 * The request snapshot logged to AiLlmMessage for each agent invocation.
 *
 * This is a record of what was asked for, not the wire payload laravel/ai
 * actually sends — that is captured verbatim by the raw exchange recorder.
 */
class RequestPayloadBuilder
{
    /**
     * @param iterable<int, Message> $history
     * @return array<string, mixed>
     */
    public function build(
        ?string $model,
        ?int $maxTokens,
        ?float $temperature,
        ?string $systemPrompt,
        iterable $history,
        string $prompt,
    ): array {
        $messages = [];

        foreach ($history as $message) {
            $messages[] = [
                'role' => $message->role->value,
                'content' => $message->content,
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ];

        if ($temperature !== null) {
            $payload['temperature'] = $temperature;
        }

        if ($systemPrompt !== null) {
            $payload['system'] = $systemPrompt;
        }

        return $payload;
    }
}

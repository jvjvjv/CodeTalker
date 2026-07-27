<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

use Jvjvjv\CodeTalker\Enums\AiInteractionStatus;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiInteractionLog;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Services\ConversationUsageService;
use Throwable;

/**
 * Persists what a turn produced: the assistant's message, the interaction log,
 * and the conversation's refreshed usage totals.
 */
class TurnRecorder
{
    public function __construct(
        private ConversationUsageService $conversationUsageService,
    ) {
    }

    /**
     * Record a turn that reached the end of its streaming loop — whether it
     * finished normally, was cut off by the duration guard, or was abandoned by
     * the browser.
     */
    public function recordCompletedTurn(
        AiConversation $conversation,
        ResponseBlocks $blocks,
        TurnOutcome $outcome,
        ?int $inputTokens,
        ?int $outputTokens,
    ): void {
        $text = $blocks->text();
        $reasoning = $blocks->reasoning();

        // Persist whatever was produced even when the turn was cut off by
        // the max-duration guard — including reasoning-only content, so a
        // model stuck deliberating without ever answering doesn't vanish
        // without a trace.
        if ($text !== '' || $reasoning !== '') {
            AiConversationMessage::create([
                'ai_conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $text,
                'reasoning_content' => $reasoning !== '' ? $reasoning : null,
                'blocks' => $blocks->toArray(),
                'metadata' => [
                    'input_tokens' => $inputTokens ?: null,
                    'output_tokens' => $outputTokens ?: null,
                    'model' => $conversation->aiSystem->model,
                ],
            ]);
        }

        $pricingSnapshot = $this->conversationUsageService->pricingSnapshotForSystem(
            $conversation->aiSystem,
            $conversation->aiSystem->model,
        );

        $log = [
            'ai_system_id' => $conversation->aiSystem->id,
            'ai_conversation_id' => $conversation->id,
            'ai_chat_bot_id' => $conversation->ai_chat_bot_id,
            'user_id' => $conversation->user_id,
            'feature' => $conversation->feature,
            'input_tokens' => $inputTokens ?: null,
            'output_tokens' => $outputTokens ?: null,
            'model' => $conversation->aiSystem->model,
            'input_token_price_snapshot' => $pricingSnapshot['input_token_price_snapshot'],
            'output_token_price_snapshot' => $pricingSnapshot['output_token_price_snapshot'],
            'duration_ms' => $outcome->durationMs,
            'status' => $outcome->maxDurationExceeded ? AiInteractionStatus::Error : AiInteractionStatus::Success,
        ];

        if ($outcome->maxDurationExceeded) {
            $log['error_message'] = $outcome->maxDurationMessage;
            $log['provider_metadata'] = ['error_reason' => 'max_stream_duration'];
        }

        AiInteractionLog::create($log);

        $this->conversationUsageService->syncConversation($conversation->fresh());
    }

    /**
     * Record a turn that never completed — an unsupported provider, an
     * unrecoverable in-stream error event, or any other provider failure.
     *
     * @param array<string, mixed> $requestPayload
     */
    public function recordFailure(
        AiConversation $conversation,
        int $turnNumber,
        array $requestPayload,
        Throwable $exception,
        float $startedAt,
    ): void {
        AiLlmMessage::create([
            'ai_conversation_id' => $conversation->id,
            'direction' => 'request',
            'turn_number' => (string) $turnNumber,
            'request_data' => $requestPayload + ['error' => $exception->getMessage(), 'error_reason' => 'provider_error'],
            'created_at' => now(),
        ]);

        AiInteractionLog::create([
            'ai_system_id' => $conversation->aiSystem->id,
            'ai_conversation_id' => $conversation->id,
            'ai_chat_bot_id' => $conversation->ai_chat_bot_id,
            'user_id' => $conversation->user_id,
            'feature' => $conversation->feature,
            'model' => $conversation->aiSystem->model,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'status' => AiInteractionStatus::Error,
            'error_message' => $exception->getMessage(),
        ]);
    }
}

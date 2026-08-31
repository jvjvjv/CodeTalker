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
        $incompleteReason = $this->incompleteReason($outcome);

        // Persist whatever the turn produced, and record the turn itself even
        // when it produced nothing visible.
        //
        // A turn cut short still happened: its tool calls changed state on the
        // host's side, and the user is owed something other than silence under
        // their question. Writing only when there was text or reasoning meant a
        // turn abandoned during prompt processing — which, on a large context,
        // is where most of a turn's wall-clock time goes — vanished entirely,
        // leaving a user message with no reply beneath it and no record that
        // anything had ever run.
        $producedSomething = $text !== '' || $reasoning !== '' || $outcome->toolCalls !== [];

        if ($producedSomething || $incompleteReason !== null) {
            AiConversationMessage::create([
                'ai_conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $text,
                'reasoning_content' => $reasoning !== '' ? $reasoning : null,
                'blocks' => $blocks->toArray(),
                // Persisted so the conversation store can replay this turn as a
                // tool call plus its result. Without them, history reconstruction
                // flattens a tool-using turn down to whatever text it happened to
                // produce alongside the call.
                'tool_calls' => $outcome->toolCalls !== [] ? $outcome->toolCalls : null,
                'tool_results' => $outcome->toolResults !== [] ? $outcome->toolResults : null,
                'usage' => [
                    'prompt_tokens' => $inputTokens ?: null,
                    'completion_tokens' => $outputTokens ?: null,
                ],
                'metadata' => [
                    'input_tokens' => $inputTokens ?: null,
                    'output_tokens' => $outputTokens ?: null,
                    'model' => $conversation->aiSystem->model,
                    // The flag a host renders "this reply was interrupted"
                    // from. Always present, so a host can read it without
                    // having to distinguish "complete" from "stored before
                    // this existed".
                    'incomplete' => $incompleteReason !== null,
                    'incomplete_reason' => $incompleteReason,
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
            'ai_persona_id' => $conversation->ai_persona_id,
            'user_id' => $conversation->user_id,
            'feature' => $conversation->feature,
            'input_tokens' => $inputTokens ?: null,
            'output_tokens' => $outputTokens ?: null,
            'model' => $conversation->aiSystem->model,
            'input_token_price_snapshot' => $pricingSnapshot['input_token_price_snapshot'],
            'output_token_price_snapshot' => $pricingSnapshot['output_token_price_snapshot'],
            'duration_ms' => $outcome->durationMs,
            // A turn that was cut short is never logged as a success: doing so
            // made a truncated turn indistinguishable from a clean one in
            // every dashboard the logs feed.
            'status' => match (true) {
                $outcome->maxDurationExceeded => AiInteractionStatus::Error,
                $outcome->clientAborted => AiInteractionStatus::Aborted,
                default => AiInteractionStatus::Success,
            },
        ];

        if ($outcome->maxDurationExceeded) {
            $log['error_message'] = $outcome->maxDurationMessage;
            $log['provider_metadata'] = ['error_reason' => 'max_stream_duration'];
        } elseif ($outcome->clientAborted) {
            $log['provider_metadata'] = ['error_reason' => 'client_aborted'];
        }

        AiInteractionLog::create($log);

        $this->conversationUsageService->syncConversation($conversation->fresh());
    }

    /**
     * Why the turn stopped short, or null if it ran to completion.
     */
    private function incompleteReason(TurnOutcome $outcome): ?string
    {
        return match (true) {
            $outcome->maxDurationExceeded => 'max_stream_duration',
            $outcome->clientAborted => 'client_aborted',
            default => null,
        };
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
            'ai_persona_id' => $conversation->ai_persona_id,
            'user_id' => $conversation->user_id,
            'feature' => $conversation->feature,
            'model' => $conversation->aiSystem->model,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'status' => AiInteractionStatus::Error,
            'error_message' => $exception->getMessage(),
        ]);
    }
}

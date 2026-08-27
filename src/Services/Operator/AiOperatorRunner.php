<?php

namespace Jvjvjv\CodeTalker\Services\Operator;

use Jvjvjv\CodeTalker\Enums\AiConversationStatus;
use Jvjvjv\CodeTalker\Enums\AiInteractionStatus;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiInteractionLog;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Models\AiOperator;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\RequestPayloadBuilder;
use Jvjvjv\CodeTalker\Services\ConversationUsageService;
use Jvjvjv\CodeTalker\Services\LaravelAi\AgentFactory;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\Mcp\ChatBotToolRegistry;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeContext;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeFrame;
use Laravel\Ai\Responses\Data\FinishReason;
use RuntimeException;

/**
 * Executes one bounded AiOperator run: interpolate the prompt, call the agent
 * non-streaming (nothing is listening for SSE), and record it as a single-turn
 * AiConversation — reusing AiLlmMessage/RawExchangeContext/ConversationUsageService
 * rather than a dedicated logging path. See design.md's "An operator run is
 * recorded as an AiConversation" decision.
 *
 * Deliberately not ConversationTurnRunner: no browser is streaming this, and a
 * max-tokens stop here is a signal the operator/config needs attention, not
 * something to continue past.
 */
class AiOperatorRunner
{
    public function __construct(
        private AgentFactory $agentFactory,
        private AiSystemProviderConfigurator $providerConfigurator,
        private RawExchangeContext $rawExchangeContext,
        private OperatorPromptInterpolator $interpolator,
        private RequestPayloadBuilder $payloads,
        private ConversationUsageService $conversationUsageService,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @throws RuntimeException if a prompt placeholder is unresolved, or the run
     *         does not finish successfully (e.g. it stops on the token limit)
     */
    public function run(AiOperator $operator, array $context): AiConversation
    {
        $system = $operator->aiSystem;
        $prompt = $this->interpolator->interpolate($operator->prompt_template, $context);

        $conversation = AiConversation::create([
            'ai_system_id' => $operator->ai_system_id,
            'ai_operator_id' => $operator->id,
            'feature' => $operator->featureKey(),
            'status' => AiConversationStatus::Active,
            'context' => $context,
        ]);

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $prompt,
        ]);

        $requestData = $this->payloads->build(
            $system->model,
            $system->max_tokens,
            $system->temperature !== null ? (float) $system->temperature : null,
            null,
            [],
            $prompt,
        );

        $requestMessage = AiLlmMessage::create([
            'ai_conversation_id' => $conversation->id,
            'direction' => 'request',
            'turn_number' => 1,
            'request_data' => $requestData,
            'created_at' => now(),
        ]);

        $startedAt = microtime(true);

        $this->rawExchangeContext->push(RawExchangeFrame::forSystem(
            $system,
            $this->providerConfigurator,
            aiConversationId: $conversation->id,
            aiLlmMessageId: $requestMessage->id,
        ));

        try {
            $agent = $this->agentFactory->forSystem(
                $system,
                tools: $this->toolsFor($operator, $conversation),
            );

            $response = $agent->prompt($prompt);
        } finally {
            $this->rawExchangeContext->pop();
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        $finishReason = $response->steps->last()?->finishReason;

        AiLlmMessage::create([
            'ai_conversation_id' => $conversation->id,
            'direction' => 'response',
            'turn_number' => 1,
            'request_data' => $requestData,
            'response_data' => [
                'text' => $response->text,
                'stop_reason' => $finishReason?->value,
                'input_tokens' => $response->usage->promptTokens ?: null,
                'output_tokens' => $response->usage->completionTokens ?: null,
                'model' => $system->model,
                'tool_calls' => $response->toolCalls->map(fn ($call) => [
                    'id' => $call->id,
                    'name' => $call->name,
                ])->all(),
            ],
            'duration_ms' => $durationMs,
            'created_at' => now(),
        ]);

        // Logged as Success regardless of stop reason: real tokens were billed
        // by the provider either way, and ConversationUsageService/the usage
        // rollup should reflect that. Whether the *run* is accepted (below) is
        // a separate question from whether it cost anything.
        $pricingSnapshot = $this->conversationUsageService->pricingSnapshotForSystem($system, $system->model);

        AiInteractionLog::create([
            'ai_system_id' => $system->id,
            'ai_conversation_id' => $conversation->id,
            'ai_persona_id' => null,
            'user_id' => null,
            'feature' => $conversation->feature,
            'input_tokens' => $response->usage->promptTokens ?: null,
            'output_tokens' => $response->usage->completionTokens ?: null,
            'model' => $system->model,
            'input_token_price_snapshot' => $pricingSnapshot['input_token_price_snapshot'],
            'output_token_price_snapshot' => $pricingSnapshot['output_token_price_snapshot'],
            'duration_ms' => $durationMs,
            'status' => AiInteractionStatus::Success,
        ]);

        $this->conversationUsageService->syncConversation($conversation->fresh());

        if ($finishReason !== FinishReason::Stop) {
            $conversation->update(['status' => AiConversationStatus::Pass]);

            throw new RuntimeException(
                "Operator '{$operator->slug}' run did not finish successfully (stop reason: "
                . ($finishReason?->value ?? 'unknown') . ').'
            );
        }

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $response->text,
        ]);

        $conversation->update(['status' => AiConversationStatus::Completed]);

        return $conversation;
    }

    /**
     * @return array<int, object>
     */
    private function toolsFor(AiOperator $operator, AiConversation $conversation): array
    {
        $allowedTools = $operator->allowed_tools ?? $operator->aiSystem->allowed_tools ?? [];

        if ($allowedTools === []) {
            return [];
        }

        return (new ChatBotToolRegistry($conversation, $allowedTools))->toLaravelAiTools();
    }
}

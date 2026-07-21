<?php

namespace Jvjvjv\CodeTalker\Services;

use Jvjvjv\CodeTalker\Enums\AiConversationStatus;
use Jvjvjv\CodeTalker\Enums\AiInteractionStatus;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiInteractionLog;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Services\LaravelAi\AgentFactory;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\LaravelAi\StreamTranslator;
use Jvjvjv\CodeTalker\Services\Mcp\ChatBotToolRegistry;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeContext;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeFrame;
use Generator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\Error as ErrorEvent;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use RuntimeException;

class AiChatBotConversationService
{
    /**
     * Maximum number of times a turn is re-prompted with "Continue." after the
     * model stops on the max-tokens limit. Tool-use iterations are handled by
     * laravel/ai's agentic loop (CodeTalkerAgent::maxSteps) and do not count
     * against this.
     */
    private const MAX_CONTINUATION_ATTEMPTS = 3;

    public function __construct(
        private AgentFactory $agentFactory,
        private AiMemoryService $memoryService,
        private ConversationUsageService $conversationUsageService,
        private RawExchangeContext $rawExchangeContext,
        private AiSystemProviderConfigurator $providerConfigurator,
    ) {
    }

    public function startConversation(AiChatBot $bot, mixed $user = null, ?string $visitorName = null, ?string $visitorEmail = null): AiConversation
    {
        $conversation = AiConversation::create([
            'user_id' => $user?->id,
            'ai_system_id' => $bot->ai_system_id,
            'ai_chat_bot_id' => $bot->id,
            'feature' => $bot->featureKey(),
            'title' => null,
            'visitor_name' => $visitorName,
            'visitor_email' => $visitorEmail,
            'status' => AiConversationStatus::Active,
            'context' => [
                'bot_slug' => $bot->slug,
                'bot_name' => $bot->name,
            ],
        ]);

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'system',
            'content' => $this->buildSystemPrompt($bot, $visitorName, $visitorEmail),
        ]);

        return $conversation;
    }

    /**
     * Continue a bot conversation by streaming the assistant response.
     *
     * Yields SSE lines ("data: {...}\n\n") in the same Anthropic-shaped wire
     * format the browser chat UI has always consumed, ending with
     * "data: [DONE]\n\n". laravel/ai owns the provider call and the tool loop;
     * this method owns persistence, logging, and the browser stream.
     *
     * @return Generator<int, string>
     */
    public function continueConversation(AiConversation $conversation, string $userMessage): Generator
    {
        $conversation->loadMissing(['aiSystem', 'aiChatBot', 'messages']);

        $userMessageRecord = AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        if (blank($conversation->title)) {
            $conversation->forceFill([
                'title' => $this->titleFromUserMessage($userMessage),
            ])->save();
        }

        $allMessages = $conversation->messages()->orderBy('created_at')->orderBy('id')->get();
        $systemPrompt = null;
        $history = [];

        foreach ($allMessages as $message) {
            if ($message->role === 'system') {
                $systemPrompt = $message->content;

                continue;
            }

            // The just-persisted user message becomes the prompt, not history.
            if ($message->id === $userMessageRecord->id) {
                continue;
            }

            $content = (string) $message->content;

            if ($message->role === 'assistant') {
                if (trim($content) === '') {
                    continue;
                }

                $history[] = new AssistantMessage($content);
            } else {
                $history[] = new UserMessage($content);
            }
        }

        $system = $conversation->aiSystem;
        $turnNumber = $this->getTurnNumberForConversation($conversation);

        $startTime = microtime(true);
        $resolvedModel = $system->model;
        $maxTokens = $system->max_tokens;
        $resolvedTemperature = $conversation->aiChatBot?->resolvedTemperature();
        $maxStreamSeconds = (int) config('code-talker.conversations.max_stream_seconds', 300);

        $toolRegistry = $conversation->aiChatBot?->tools_enabled
            ? new ChatBotToolRegistry(
                $conversation,
                $system->allowed_tools ?? [],
            )
            : null;
        $tools = $toolRegistry?->toLaravelAiTools() ?? [];

        $requestPayload = $this->buildRequestPayload(
            $resolvedModel,
            $maxTokens,
            $resolvedTemperature,
            $systemPrompt,
            $history,
            $userMessage,
        );

        $translator = new StreamTranslator();
        $blocks = [];
        $durationMs = 0;

        $appendToBlocks = static function (string $type, string $delta) use (&$blocks): void {
            if ($blocks !== [] && $blocks[\count($blocks) - 1]['type'] === $type) {
                $blocks[\count($blocks) - 1]['content'] .= $delta;
            } else {
                $blocks[] = ['type' => $type, 'content' => $delta];
            }
        };

        try {
            $agent = $this->agentFactory->forSystem(
                $system,
                instructions: $systemPrompt ?? '',
                messages: $history,
                tools: $tools,
                temperature: $resolvedTemperature,
            );

            yield 'data: ' . json_encode([
                'type' => 'status',
                'phase' => 'model_loading',
                'message' => 'Waiting for model response...',
            ]) . "\n\n";

            $prompt = $userMessage;
            $clientAborted = false;

            for ($attempt = 0; $attempt < self::MAX_CONTINUATION_ATTEMPTS; $attempt++) {
                $attemptTurnNumber = $attempt === 0 ? (string) $turnNumber : "{$turnNumber}.{$attempt}";

                $requestPayload = $this->buildRequestPayload(
                    $resolvedModel,
                    $maxTokens,
                    $resolvedTemperature,
                    $systemPrompt,
                    $agent->messages(),
                    $prompt,
                );

                $requestMessage = AiLlmMessage::create([
                    'ai_conversation_id' => $conversation->id,
                    'direction' => 'request',
                    'turn_number' => $attemptTurnNumber,
                    'request_data' => $requestPayload,
                    'created_at' => now(),
                ]);

                /** @var array<int, StreamEvent> $events */
                $events = [];
                $toolCalls = [];

                $this->rawExchangeContext->push(RawExchangeFrame::forSystem(
                    $system,
                    $this->providerConfigurator,
                    aiConversationId: $conversation->id,
                    aiLlmMessageId: $requestMessage->id,
                ));

                try {
                    foreach ($agent->stream($prompt) as $event) {
                    // The browser can abort an in-flight turn (Cancel button / ESC),
                    // which closes the HTTP connection. Stop generating as soon as the
                    // disconnect is visible so we neither keep paying for tokens nor
                    // spin through further continuation attempts. Whatever streamed so
                    // far is still persisted below as a partial turn.
                    if ($this->clientAborted()) {
                        $clientAborted = true;
                        break;
                    }

                    Log::debug('Chat bot API stream event', [
                        'conversation_id' => $conversation->id,
                        'ai_chat_bot_id' => $conversation->ai_chat_bot_id,
                        'ai_system_id' => $conversation->ai_system_id,
                        'turn_number' => $turnNumber,
                        'attempt' => $attempt,
                        'event_type' => class_basename($event),
                    ]);

                    $events[] = $event;

                    // A non-recoverable provider error can arrive as an in-stream
                    // event (e.g. LM Studio returns HTTP 200 then an SSE
                    // "event: error" like "Context size has been exceeded.").
                    // Fail the turn instead of finishing it as a silent success.
                    if ($event instanceof ErrorEvent && ! $event->recoverable) {
                        throw new RuntimeException($event->message);
                    }

                    // Bound total wall-clock time for the turn so a runaway
                    // generation (e.g. a reasoning model looping until it
                    // overflows the context window) cannot hang indefinitely.
                    if ($maxStreamSeconds > 0 && $this->streamElapsedSeconds($startTime) > $maxStreamSeconds) {
                        throw new RuntimeException(
                            "The response exceeded the maximum stream duration of {$maxStreamSeconds}s and was aborted.",
                        );
                    }

                    if ($event instanceof ToolCallEvent) {
                        $toolCalls[] = [
                            'id' => $event->toolCall->id,
                            'name' => $event->toolCall->name,
                        ];
                    }

                    foreach ($translator->translate($event) as $browserEvent) {
                        if ($browserEvent['type'] === 'content_block_delta') {
                            $appendToBlocks('text', $browserEvent['delta']['text']);
                        } elseif ($browserEvent['type'] === 'reasoning_block_delta') {
                            $appendToBlocks('reasoning', $browserEvent['delta']['reasoning']);
                        }

                        yield 'data: ' . json_encode($browserEvent) . "\n\n";
                    }
                    }
                } finally {
                    $this->rawExchangeContext->pop();
                }

                $attemptUsage = StreamEnd::combineUsage($events);
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);

                AiLlmMessage::create([
                    'ai_conversation_id' => $conversation->id,
                    'direction' => 'response',
                    'turn_number' => $attemptTurnNumber,
                    'request_data' => $requestPayload,
                    'response_data' => [
                        'events' => array_map(static fn (StreamEvent $event): array => $event->toArray(), $events),
                        'stop_reason' => $translator->stopReason(),
                        'input_tokens' => $attemptUsage->promptTokens ?: null,
                        'output_tokens' => $attemptUsage->completionTokens ?: null,
                        'model' => $resolvedModel,
                        'tool_calls' => $toolCalls,
                    ],
                    'duration_ms' => $durationMs,
                    'created_at' => now(),
                ]);

                if ($clientAborted) {
                    break;
                }

                if ($translator->lastReason() !== 'length') {
                    break;
                }

                $accumulatedText = collect($blocks)->where('type', 'text')->pluck('content')->implode('');
                $agent->append(new UserMessage($prompt), new AssistantMessage($accumulatedText));
                $prompt = 'Continue.';
            }

            foreach ($translator->finish() as $browserEvent) {
                yield 'data: ' . json_encode($browserEvent) . "\n\n";
            }

            yield "data: [DONE]\n\n";

            $fullResponse = collect($blocks)->where('type', 'text')->pluck('content')->implode('');
            $thinkingContent = collect($blocks)->where('type', 'reasoning')->pluck('content')->implode("\n\n");

            $totalInputTokens = $translator->inputTokens();
            $totalOutputTokens = $translator->outputTokens();

            $pricingSnapshot = $this->conversationUsageService->pricingSnapshotForSystem(
                $conversation->aiSystem,
                $conversation->aiSystem->model,
            );

            if ($fullResponse !== '') {
                AiConversationMessage::create([
                    'ai_conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $fullResponse,
                    'reasoning_content' => $thinkingContent !== '' ? $thinkingContent : null,
                    'blocks' => $blocks !== [] ? $blocks : null,
                    'metadata' => [
                        'input_tokens' => $totalInputTokens ?: null,
                        'output_tokens' => $totalOutputTokens ?: null,
                        'model' => $conversation->aiSystem->model,
                    ],
                ]);
            }

            AiInteractionLog::create([
                'ai_system_id' => $conversation->aiSystem->id,
                'ai_conversation_id' => $conversation->id,
                'ai_chat_bot_id' => $conversation->ai_chat_bot_id,
                'user_id' => $conversation->user_id,
                'feature' => $conversation->feature,
                'input_tokens' => $totalInputTokens ?: null,
                'output_tokens' => $totalOutputTokens ?: null,
                'model' => $resolvedModel,
                'input_token_price_snapshot' => $pricingSnapshot['input_token_price_snapshot'],
                'output_token_price_snapshot' => $pricingSnapshot['output_token_price_snapshot'],
                'duration_ms' => $durationMs,
                'status' => AiInteractionStatus::Success,
            ]);

            $this->conversationUsageService->syncConversation($conversation->fresh());
        } catch (\Throwable $exception) {
            AiLlmMessage::create([
                'ai_conversation_id' => $conversation->id,
                'direction' => 'request',
                'turn_number' => (string) $turnNumber,
                'request_data' => $requestPayload + ['error' => $exception->getMessage()],
                'created_at' => now(),
            ]);

            AiInteractionLog::create([
                'ai_system_id' => $conversation->aiSystem->id,
                'ai_conversation_id' => $conversation->id,
                'ai_chat_bot_id' => $conversation->ai_chat_bot_id,
                'user_id' => $conversation->user_id,
                'feature' => $conversation->feature,
                'model' => $resolvedModel ?? $conversation->aiSystem->model,
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'status' => AiInteractionStatus::Error,
                'error_message' => $exception->getMessage(),
            ]);

            yield 'data: ' . json_encode(['type' => 'error', 'message' => $exception->getMessage()]) . "\n\n";
        }
    }

    /**
     * Wall-clock seconds elapsed since the turn started. Extracted so tests can
     * drive the max-stream-duration guard deterministically.
     */
    protected function streamElapsedSeconds(float $startedAt): float
    {
        return microtime(true) - $startedAt;
    }

    /**
     * Whether the browser has hung up on the streaming response (Cancel/ESC).
     * Extracted so tests can drive the client-abort guard deterministically.
     * PHP only flips this flag once output is flushed to the dead connection,
     * so the controller sets ignore_user_abort(true) and keeps flushing.
     */
    protected function clientAborted(): bool
    {
        return connection_aborted() !== 0;
    }

    /**
     * The request snapshot logged to AiLlmMessage for each agent invocation.
     *
     * @param iterable<int, Message> $history
     * @return array<string, mixed>
     */
    private function buildRequestPayload(
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

    private function getTurnNumberForConversation(AiConversation $conversation): int
    {
        $maxTurn = AiLlmMessage::query()
            ->where('ai_conversation_id', $conversation->id)
            ->max('turn_number');

        if ($maxTurn === null || !is_numeric($maxTurn)) {
            return 1;
        }

        return (int) $maxTurn + 1;
    }

    private function buildSystemPrompt(AiChatBot $bot, ?string $visitorName = null, ?string $visitorEmail = null): string
    {
        return $this->buildSystemPromptForBot($bot, null, $visitorName, $visitorEmail);
    }

    private function buildSystemPromptForBot(AiChatBot $bot, ?AiConversation $conversation = null, ?string $visitorName = null, ?string $visitorEmail = null): string
    {
        $replacements = [
            '{{bot_name}}' => $bot->name,
            '{{bot_slug}}' => $bot->slug,
            '{{bot_description}}' => $bot->description ?? '',
            '{{visitor_name}}' => $visitorName ?? '',
            '{{visitor_email}}' => $visitorEmail ?? '',
        ];

        $prompt = strtr($bot->prompt_template, $replacements);
        $systemPrompt = trim((string) $bot->aiSystem?->system_prompt);

        $memoryUserId = null;
        $memoryVisitorEmail = null;

        if ($conversation !== null) {
            $memoryUserId = $conversation->user_id;
            $memoryVisitorEmail = $conversation->visitor_email;
        } elseif (auth()->check()) {
            $memoryUserId = auth()->id();
        }

        $memoryPrompt = trim($this->memoryService->getMemoriesForPrompt(
            $bot->featureKey(),
            $memoryUserId,
            $memoryVisitorEmail
        ));

        return collect([
            $systemPrompt !== '' ? $systemPrompt : null,
            $prompt,
            $memoryPrompt !== '' ? "## Learned Insights\n{$memoryPrompt}" : null,
        ])->filter()->implode("\n\n");
    }

    private function titleFromUserMessage(string $userMessage): string
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

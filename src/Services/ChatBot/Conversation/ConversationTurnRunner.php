<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

use Generator;
use Illuminate\Support\Facades\Log;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\LaravelAi\CodeTalkerAgent;
use Jvjvjv\CodeTalker\Services\LaravelAi\StreamTranslator;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeContext;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeFrame;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\Error as ErrorEvent;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use RuntimeException;

/**
 * Streams one conversation turn, re-prompting when the model stops on the
 * token limit rather than because it was finished.
 *
 * Tool-use iterations are not handled here — laravel/ai's agentic loop owns
 * those, and they do not count against the continuation budget.
 */
class ConversationTurnRunner
{
    /**
     * Maximum number of times a turn is re-prompted with "Continue." after the
     * model stops on the max-tokens limit.
     */
    private const MAX_CONTINUATION_ATTEMPTS = 3;

    public function __construct(
        private RawExchangeContext $rawExchangeContext,
        private AiSystemProviderConfigurator $providerConfigurator,
        private RequestPayloadBuilder $payloads,
        private TurnSequence $turns,
    ) {
    }

    /**
     * Yields browser SSE frames and returns how the turn ended.
     *
     * @return Generator<int, string, mixed, TurnOutcome>
     */
    public function run(
        AiConversation $conversation,
        CodeTalkerAgent $agent,
        StreamTranslator $translator,
        ResponseBlocks $blocks,
        TurnGuards $guards,
        TurnRequestPayload $requestPayload,
        string $userMessage,
        int $turnNumber,
        float $startedAt,
        ?string $systemPrompt,
    ): Generator {
        $system = $conversation->aiSystem;
        $resolvedModel = $system->model;
        $maxTokens = $system->max_tokens;
        $temperature = $conversation->aiChatBot?->resolvedTemperature();
        $maxStreamSeconds = (int) config('code-talker.conversations.max_stream_seconds', 300);

        $prompt = $userMessage;
        $clientAborted = false;
        $maxDurationExceeded = false;
        $maxDurationMessage = null;
        $durationMs = 0;

        // Bounds a single provider request, not the whole turn: reset on
        // every StreamStart, since each continuation attempt AND each
        // internal tool-call step (laravel/ai's TextGenerationLoop issues
        // one HTTP request per step) is a fresh generation that deserves
        // its own budget rather than a shrinking share of the turn's.
        $stepStartedAt = $startedAt;

        for ($attempt = 0; $attempt < self::MAX_CONTINUATION_ATTEMPTS; $attempt++) {
            $attemptTurnNumber = $this->turns->labelFor($turnNumber, $attempt);

            $requestPayload->record($this->payloads->build(
                $resolvedModel,
                $maxTokens,
                $temperature,
                $systemPrompt,
                $agent->messages(),
                $prompt,
            ));

            $requestMessage = AiLlmMessage::create([
                'ai_conversation_id' => $conversation->id,
                'direction' => 'request',
                'turn_number' => $attemptTurnNumber,
                'request_data' => $requestPayload->latest(),
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
                    // far is still persisted by the caller as a partial turn.
                    if ($guards->clientAborted()) {
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

                    // Each provider request (a continuation attempt, or an
                    // internal tool-call step within the same attempt) starts
                    // with its own StreamStart, so this is a fresh generation
                    // that deserves a fresh budget rather than what's left of
                    // the turn's.
                    if ($event instanceof StreamStart) {
                        $stepStartedAt = microtime(true);
                    }

                    // A non-recoverable provider error can arrive as an in-stream
                    // event (e.g. LM Studio returns HTTP 200 then an SSE
                    // "event: error" like "Context size has been exceeded.").
                    // Fail the turn instead of finishing it as a silent success.
                    if ($event instanceof ErrorEvent && ! $event->recoverable) {
                        throw new RuntimeException($event->message);
                    }

                    // Bound wall-clock time for the current request so a runaway
                    // generation (e.g. a reasoning model looping until it
                    // overflows the context window) cannot hang indefinitely.
                    // This stops the turn like a client abort (above) rather than
                    // throwing, so whatever streamed so far — including
                    // reasoning-only content, e.g. a model stuck deliberating
                    // and never producing an answer — is still persisted
                    // instead of silently vanishing.
                    if ($maxStreamSeconds > 0 && $guards->elapsedSince($stepStartedAt) > $maxStreamSeconds) {
                        $maxDurationExceeded = true;
                        $maxDurationMessage = "The response exceeded the maximum stream duration of {$maxStreamSeconds}s and was aborted.";

                        break;
                    }

                    if ($event instanceof ToolCallEvent) {
                        $toolCalls[] = [
                            'id' => $event->toolCall->id,
                            'name' => $event->toolCall->name,
                        ];
                    }

                    foreach ($translator->translate($event) as $browserEvent) {
                        if ($browserEvent['type'] === 'content_block_delta') {
                            $blocks->append('text', $browserEvent['delta']['text']);
                        } elseif ($browserEvent['type'] === 'reasoning_block_delta') {
                            $blocks->append('reasoning', $browserEvent['delta']['reasoning']);
                        }

                        yield 'data: ' . json_encode($browserEvent) . "\n\n";
                    }
                }
            } finally {
                $this->rawExchangeContext->pop();
            }

            $attemptUsage = StreamEnd::combineUsage($events);
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            AiLlmMessage::create([
                'ai_conversation_id' => $conversation->id,
                'direction' => 'response',
                'turn_number' => $attemptTurnNumber,
                'request_data' => $requestPayload->latest(),
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

            if ($clientAborted || $maxDurationExceeded) {
                break;
            }

            if ($translator->lastReason() !== 'length') {
                break;
            }

            // The model ran out of room mid-answer: feed back what it has said
            // so far and ask it to keep going.
            $agent->append(new UserMessage($prompt), new AssistantMessage($blocks->text()));
            $prompt = 'Continue.';
        }

        return new TurnOutcome(
            clientAborted: $clientAborted,
            maxDurationExceeded: $maxDurationExceeded,
            maxDurationMessage: $maxDurationMessage,
            durationMs: $durationMs,
        );
    }
}

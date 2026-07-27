<?php

namespace Jvjvjv\CodeTalker\Services;

use Generator;
use Jvjvjv\CodeTalker\Enums\AiConversationStatus;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\ConversationTitle;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\ConversationTurnRunner;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\RequestPayloadBuilder;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\ResponseBlocks;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\SystemPromptBuilder;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\TranscriptBuilder;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\TurnGuards;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\TurnRecorder;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\TurnRequestPayload;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\TurnSequence;
use Jvjvjv\CodeTalker\Services\LaravelAi\AgentFactory;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\LaravelAi\StreamTranslator;
use Jvjvjv\CodeTalker\Services\Mcp\ChatBotToolRegistry;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeContext;

class AiChatBotConversationService
{
    private SystemPromptBuilder $systemPrompts;

    private TranscriptBuilder $transcripts;

    private ConversationTitle $titles;

    private TurnSequence $turns;

    private ConversationTurnRunner $turnRunner;

    private TurnRecorder $turnRecorder;

    private RequestPayloadBuilder $payloads;

    public function __construct(
        private AgentFactory $agentFactory,
        private AiMemoryService $memoryService,
        private ConversationUsageService $conversationUsageService,
        private RawExchangeContext $rawExchangeContext,
        private AiSystemProviderConfigurator $providerConfigurator,
    ) {
        // Built from the injected dependencies rather than taken as extra
        // constructor arguments, so this signature stays as host apps and tests
        // construct it.
        $this->systemPrompts = new SystemPromptBuilder($memoryService);
        $this->transcripts = new TranscriptBuilder();
        $this->titles = new ConversationTitle();
        $this->turns = new TurnSequence();
        $this->payloads = new RequestPayloadBuilder();
        $this->turnRecorder = new TurnRecorder($conversationUsageService);
        $this->turnRunner = new ConversationTurnRunner(
            $rawExchangeContext,
            $providerConfigurator,
            $this->payloads,
            $this->turns,
        );
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
            'content' => $this->systemPrompts->build($bot, null, $visitorName, $visitorEmail),
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
                'title' => $this->titles->fromUserMessage($userMessage),
            ])->save();
        }

        $transcript = $this->transcripts->build($conversation, $userMessageRecord);

        $system = $conversation->aiSystem;
        $turnNumber = $this->turns->nextFor($conversation);
        $startTime = microtime(true);
        $temperature = $conversation->aiChatBot?->resolvedTemperature();

        $requestPayload = new TurnRequestPayload($this->payloads->build(
            $system->model,
            $system->max_tokens,
            $temperature,
            $transcript->systemPrompt,
            $transcript->history,
            $userMessage,
        ));

        $translator = new StreamTranslator();
        $blocks = new ResponseBlocks();

        try {
            $agent = $this->agentFactory->forSystem(
                $system,
                instructions: $transcript->systemPrompt ?? '',
                messages: $transcript->history,
                tools: $this->toolsFor($conversation),
                temperature: $temperature,
            );

            yield 'data: ' . json_encode([
                'type' => 'status',
                'phase' => 'model_loading',
                'message' => 'Waiting for model response...',
            ]) . "\n\n";

            $outcome = yield from $this->turnRunner->run(
                $conversation,
                $agent,
                $translator,
                $blocks,
                $this->turnGuards(),
                $requestPayload,
                $userMessage,
                $turnNumber,
                $startTime,
                $transcript->systemPrompt,
            );

            $this->turnRecorder->recordCompletedTurn(
                $conversation,
                $blocks,
                $outcome,
                $translator->inputTokens(),
                $translator->outputTokens(),
            );

            if ($outcome->maxDurationExceeded) {
                // Still surfaced to the browser as a failure — the guard genuinely
                // cut the turn off — but the content above is no longer lost.
                yield 'data: ' . json_encode([
                    'type' => 'error',
                    'message' => $outcome->maxDurationMessage,
                    'reason' => 'max_stream_duration',
                ]) . "\n\n";

                return;
            }

            foreach ($translator->finish() as $browserEvent) {
                yield 'data: ' . json_encode($browserEvent) . "\n\n";
            }

            yield "data: [DONE]\n\n";
        } catch (\Throwable $exception) {
            // The max-stream-duration guard no longer throws (it stops the
            // turn like a client abort so partial content is preserved, see
            // above), so anything reaching this catch is a genuine provider
            // failure — an unsupported provider, an unrecoverable in-stream
            // error event, etc.
            $this->turnRecorder->recordFailure(
                $conversation,
                $turnNumber,
                $requestPayload->latest(),
                $exception,
                $startTime,
            );

            yield 'data: ' . json_encode([
                'type' => 'error',
                'message' => $exception->getMessage(),
                'reason' => 'provider_error',
            ]) . "\n\n";
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
     * The guards, bound back to this instance so a subclass overriding either
     * hook is what the streaming loop actually consults.
     */
    private function turnGuards(): TurnGuards
    {
        return new TurnGuards(
            elapsedSeconds: fn (float $startedAt): float => $this->streamElapsedSeconds($startedAt),
            clientAborted: fn (): bool => $this->clientAborted(),
        );
    }

    /**
     * @return array<int, object>
     */
    private function toolsFor(AiConversation $conversation): array
    {
        if (!$conversation->aiChatBot?->tools_enabled) {
            return [];
        }

        return (new ChatBotToolRegistry(
            $conversation,
            $conversation->aiSystem->allowed_tools ?? [],
        ))->toLaravelAiTools();
    }
}

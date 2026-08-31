<?php

namespace Jvjvjv\CodeTalker\Services;

use Generator;
use RuntimeException;
use Jvjvjv\CodeTalker\Enums\AiConversationStatus;
use Jvjvjv\CodeTalker\Jobs\RunConversationTurnJob;
use Jvjvjv\CodeTalker\Models\AiPersona;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiTurnRun;
use Jvjvjv\CodeTalker\Services\Conversation\TurnEventStream;
use Jvjvjv\CodeTalker\Services\Conversation\TurnRunStore;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\ConversationTitle;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\ConversationTurnRunner;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\RequestPayloadBuilder;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\ResponseBlocks;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\SystemPromptBuilder;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\ConversationHistory;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\TurnGuards;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\TurnRecorder;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\TurnRequestPayload;
use Jvjvjv\CodeTalker\Services\ChatBot\Conversation\TurnSequence;
use Jvjvjv\CodeTalker\Services\LaravelAi\AgentFactory;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\LaravelAi\StreamTranslator;
use Jvjvjv\CodeTalker\Services\Mcp\ChatBotToolRegistry;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeContext;

class AiPersonaConversationService
{
    private SystemPromptBuilder $systemPrompts;

    private ConversationHistory $history;

    private ConversationTitle $titles;

    /** @var (callable(): bool)|null */
    private $cancellationCheck = null;

    /** @see usingToolPayloads() */
    private bool $includeToolPayloads = false;

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
        $this->history = new ConversationHistory(app(\Laravel\Ai\Contracts\ConversationStore::class));
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

    /**
     * Open a conversation with a persona.
     *
     * The two guards here used to live in the controller. They are enforced at
     * the service now so a host writing its own controller cannot lose them:
     * an inactive persona is not chattable, and a persona that requires visitor
     * identity has no way to attribute a conversation without one.
     *
     * @throws RuntimeException if the persona is inactive or required identity is missing
     */
    public function startConversation(AiPersona $persona, mixed $user = null, ?string $visitorName = null, ?string $visitorEmail = null): AiConversation
    {
        if (! $persona->is_active) {
            throw new RuntimeException("The persona '{$persona->slug}' is not active.");
        }

        if ($persona->require_visitor_identity && (blank($visitorName) || blank($visitorEmail))) {
            throw new RuntimeException(
                "The persona '{$persona->slug}' requires a visitor name and email."
            );
        }

        $conversation = AiConversation::create([
            'user_id' => $user?->id,
            'ai_system_id' => $persona->ai_system_id,
            'ai_persona_id' => $persona->id,
            'feature' => $persona->featureKey(),
            'title' => null,
            'visitor_name' => $visitorName,
            'visitor_email' => $visitorEmail,
            'status' => AiConversationStatus::Active,
            'context' => [
                'persona_slug' => $persona->slug,
                'persona_name' => $persona->name,
            ],
        ]);

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'system',
            'content' => $this->systemPrompts->build($persona, null, $visitorName, $visitorEmail),
        ]);

        return $conversation;
    }

    /**
     * Continue a persona conversation by streaming the assistant response.
     *
     * Yields structured events in the same Anthropic-shaped vocabulary the
     * documented stream has always used — `status`, `message_start`,
     * `content_block_delta`, `reasoning_block_delta`, `message_delta`,
     * `message_stop`, `error` — leaving the choice of transport to the caller.
     * Pass them through SseFrameEncoder to reproduce the previous wire format.
     *
     * laravel/ai owns the provider call and the tool loop; this method owns
     * persistence and logging.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function continueConversation(AiConversation $conversation, string $userMessage): Generator
    {
        $conversation->loadMissing(['aiSystem', 'aiPersona', 'messages']);

        // The controller used to do this on every message. It is not just a
        // header value — it migrates a stale hash — so it moves here rather
        // than becoming the host's job to remember.
        $conversation->generateChatHash();

        // Read history before persisting the incoming message: that message
        // becomes the prompt, so replaying it as history too would send it twice.
        $systemPrompt = $this->history->systemPromptFor($conversation);
        $history = $this->history->historyFor($conversation);

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        if (blank($conversation->title)) {
            $conversation->forceFill([
                'title' => $this->titles->fromUserMessage($userMessage),
            ])->save();
        }

        $system = $conversation->aiSystem;
        $turnNumber = $this->turns->nextFor($conversation);
        $startTime = microtime(true);
        $temperature = $conversation->aiPersona?->resolvedTemperature();

        $requestPayload = new TurnRequestPayload($this->payloads->build(
            $system->model,
            $system->max_tokens,
            $temperature,
            $systemPrompt,
            $history,
            $userMessage,
        ));

        $translator = new StreamTranslator();
        $blocks = new ResponseBlocks();

        try {
            $agent = $this->agentFactory->forSystem(
                $system,
                instructions: $systemPrompt ?? '',
                messages: $history,
                tools: $this->toolsFor($conversation),
                temperature: $temperature,
            );

            yield [
                'type' => 'status',
                'phase' => 'model_loading',
                'message' => 'Waiting for model response...',
            ];

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
                $systemPrompt,
                $this->includeToolPayloads,
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
                yield [
                    'type' => 'error',
                    'message' => $outcome->maxDurationMessage,
                    'reason' => 'max_stream_duration',
                ];

                return;
            }

            foreach ($translator->finish() as $browserEvent) {
                yield $browserEvent;
            }
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

            yield [
                'type' => 'error',
                'message' => $exception->getMessage(),
                'reason' => 'provider_error',
            ];
        }
    }

    /**
     * Run a turn detached from the caller's connection.
     *
     * The turn becomes a queued job that writes its events to a store; the
     * browser reads them with resumeTurn() and can reconnect at any point. Use
     * this instead of continueConversation() when a turn is long enough that a
     * reload or a flaky connection should not destroy it.
     *
     * The store and reader are resolved here rather than injected: this
     * service's five-argument constructor is depended on by host apps and by
     * tests that subclass it, so collaborators are built from what it has.
     */
    public function dispatchTurn(AiConversation $conversation, string $message): AiTurnRun
    {
        $run = app(TurnRunStore::class)->open($conversation, $message);

        RunConversationTurnJob::dispatch($run->id);

        return $run;
    }

    /**
     * Stream a dispatched turn's events, starting after the given sequence.
     *
     * Yields the same structured events continueConversation() does, each with
     * a `_seq` the encoder turns into an SSE id. A browser that reconnects
     * passes back the last sequence it saw and misses nothing in between.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function resumeTurn(AiTurnRun $run, int $after = 0): Generator
    {
        yield from app(TurnEventStream::class)->stream($run, $after);
    }

    /**
     * Ask a running turn to stop.
     *
     * The worker notices within a couple of seconds and stops generating;
     * whatever the turn produced by then is persisted and flagged incomplete.
     */
    public function cancelTurn(AiTurnRun $run): void
    {
        app(TurnRunStore::class)->requestCancel($run);
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
     * Supply the signal that cancels an in-flight turn.
     *
     * The default suits a web request, where the browser hanging up is the
     * signal. It is useless anywhere else — connection_aborted() reports 0 in
     * CLI and queue contexts, so the guard would silently never fire — hence a
     * host driving a turn outside a request should supply its own.
     *
     * @param callable(): bool $check
     */
    public function usingCancellationCheck(callable $check): static
    {
        $this->cancellationCheck = $check;

        return $this;
    }

    /**
     * Include each tool call's raw arguments and result on the browser-visible
     * `tool_use_progress` frames, instead of just the tool's name.
     *
     * Off by default: a tool's arguments/result can carry whatever the model
     * or a page it fetched put in them — including, since a host may have
     * enabled `allow_credential_headers`, a credential the model was handling
     * on the caller's behalf. That is not something to expose to every
     * browser by default. A host typically calls this only outside
     * production, e.g. `if (! app()->environment('production')) { ... }`,
     * as a debugging aid.
     */
    public function usingToolPayloads(bool $include = true): static
    {
        $this->includeToolPayloads = $include;

        return $this;
    }

    /**
     * Whether the turn has been cancelled.
     *
     * PHP only flips connection_aborted() once output has been flushed to the
     * dead connection, so a host streaming over HTTP must set
     * ignore_user_abort(true) and keep flushing for the default to work.
     */
    protected function clientAborted(): bool
    {
        if ($this->cancellationCheck !== null) {
            return ($this->cancellationCheck)();
        }

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
        if (!$conversation->aiPersona?->tools_enabled) {
            return [];
        }

        return (new ChatBotToolRegistry(
            $conversation,
            $conversation->aiSystem->allowed_tools ?? [],
        ))->toLaravelAiTools();
    }
}

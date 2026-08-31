<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\CodeTalkerServiceProvider;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Jobs\ProcessAiMemoryJob;
use Jvjvjv\CodeTalker\Jobs\RunConversationTurnJob;
use Jvjvjv\CodeTalker\Models\AiPersona;
use Jvjvjv\CodeTalker\Models\AiTurnRun;
use Jvjvjv\CodeTalker\Services\Conversation\TurnRunStore;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiInteractionLog;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiPersonaConversationService;
use Jvjvjv\CodeTalker\Services\ChatBot\SseFrameEncoder;
use Jvjvjv\CodeTalker\Services\AiMemoryService;
use Jvjvjv\CodeTalker\Services\ConversationUsageService;
use Jvjvjv\CodeTalker\Services\LaravelAi\AgentFactory;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\LaravelAi\CodeTalkerAgent;
use Jvjvjv\CodeTalker\Services\LaravelAi\Streaming\Heartbeat;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeContext;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Closure;
use Generator;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use RuntimeException;

class AiPersonaConversationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('code-talker.user_model', \Illuminate\Foundation\Auth\User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // AiConversation::booted() assigns a uuid, but no package migration
        // creates the column (host apps add it themselves).
        if (!Schema::hasColumn('ai_conversations', 'uuid')) {
            Schema::table('ai_conversations', function ($table): void {
                $table->string('uuid')->nullable();
            });
        }
    }

    protected function tearDown(): void
    {
        $this->unregisterFixtureToolDirectory();

        parent::tearDown();
    }

    /**
     * `CodeTalkerServiceProvider::addToolDirectory()` accumulates in a static
     * array with no unregister method — it models a host app calling it once,
     * for the life of the process. Undoing it here keeps the fixture tool
     * registered for this file's page_reload tests from leaking into other
     * tests that pin the exact discovered tool set (e.g. ChatBotToolRegistryTest,
     * ManagementServicesTest).
     */
    private function unregisterFixtureToolDirectory(): void
    {
        $property = new \ReflectionProperty(CodeTalkerServiceProvider::class, 'toolDirectories');
        $property->setAccessible(true);

        $directories = $property->getValue();
        unset($directories[__DIR__ . '/../Fixtures/Tools']);
        $property->setValue(null, $directories);
    }

    private function makePersona(array $systemAttributes = [], array $personaAttributes = []): AiPersona
    {
        $system = AiSystem::create(array_merge([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ], $systemAttributes));

        return AiPersona::create(array_merge([
            'ai_system_id' => $system->id,
            'name' => 'Test Bot',
            'slug' => 'test-bot',
            'prompt_template' => 'You are {{persona_name}}.',
            'is_active' => true,
        ], $personaAttributes));
    }

    /**
     * The service yields structured events now; the documented wire format is
     * produced by SseFrameEncoder. Piping the stream through it here keeps every
     * assertion below — on the events and on the raw lines — meaningful, and
     * means the encoder is covered by the same characterization tests.
     *
     * @return array<int, array<string, mixed>> decoded JSON events, excluding [DONE]
     */
    private function drainAndDecode(iterable $stream, array &$rawLines = []): array
    {
        $events = [];

        foreach ((new SseFrameEncoder())->encode($stream) as $line) {
            $rawLines[] = $line;

            // A heartbeat travels as an SSE comment, not a data frame. Map it
            // back to its structured form so assertions can still count it.
            if (str_starts_with($line, ':')) {
                $events[] = ['type' => 'heartbeat'];

                continue;
            }

            $payload = trim(str_replace('data: ', '', $line));

            if ($payload === '[DONE]') {
                continue;
            }

            $events[] = json_decode($payload, true);
        }

        return $events;
    }

    public function test_streams_a_text_turn_in_the_legacy_wire_format_and_persists_everything(): void
    {
        Queue::fake();
        CodeTalkerAgent::fake(['Hello world from fake']);

        $persona = $this->makePersona();
        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        $rawLines = [];
        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Hi there'), $rawLines);

        $this->assertSame("data: [DONE]\n\n", end($rawLines));

        $types = array_column($events, 'type');

        $this->assertSame('status', $types[0]);
        $this->assertSame(1, array_count_values($types)['message_start']);
        $this->assertContains('content_block_delta', $types);
        $this->assertSame(['message_delta', 'message_stop'], array_slice($types, -2));

        $text = collect($events)
            ->where('type', 'content_block_delta')
            ->pluck('delta.text')
            ->implode('');

        $this->assertSame('Hello world from fake', $text);

        $messageDelta = collect($events)->firstWhere('type', 'message_delta');
        $this->assertSame('end_turn', $messageDelta['delta']['stop_reason']);

        $assistantMessage = AiConversationMessage::where('role', 'assistant')->first();
        $this->assertNotNull($assistantMessage);
        $this->assertSame('Hello world from fake', $assistantMessage->content);

        $this->assertSame(
            ['request', 'response'],
            AiLlmMessage::orderBy('id')->pluck('direction')->all(),
        );

        $request = AiLlmMessage::where('direction', 'request')->first();
        $this->assertSame('1', $request->turn_number);
        $this->assertSame('claude-sonnet-4-6', $request->request_data['model']);
        $requestMessages = $request->request_data['messages'];
        $this->assertSame('Hi there', end($requestMessages)['content']);

        $response = AiLlmMessage::where('direction', 'response')->first();
        $this->assertSame('end_turn', $response->response_data['stop_reason']);
        $this->assertNotEmpty($response->response_data['events']);

        $log = AiInteractionLog::first();
        $this->assertSame('success', $log->status->value);

        $conversationTitle = $conversation->fresh()->title;
        $this->assertSame('Hi there', $conversationTitle);

        // Memory extraction is not per-turn. It fires once, when
        // ai:complete-idle-conversations marks the conversation Completed.
        Queue::assertNotPushed(ProcessAiMemoryJob::class);
    }

    /**
     * Characterization test: the browser wire format is a compatibility surface,
     * so a normal turn's complete event sequence — not just its highlights — is
     * pinned here against accidental reordering or additions.
     */
    public function test_a_normal_turn_emits_the_exact_sse_event_sequence(): void
    {
        Queue::fake();
        CodeTalkerAgent::fake(['Hello world from fake']);

        $persona = $this->makePersona();
        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        $rawLines = [];
        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Hi there'), $rawLines);

        $this->assertSame(
            [
                'status',
                'message_start',
                // One delta per streamed chunk of "Hello world from fake".
                'content_block_delta',
                'content_block_delta',
                'content_block_delta',
                'content_block_delta',
                'message_delta',
                'message_stop',
            ],
            array_column($events, 'type'),
        );

        // Every line is a well-formed SSE frame, and the stream is terminated.
        foreach ($rawLines as $line) {
            $this->assertStringStartsWith('data: ', $line);
            $this->assertStringEndsWith("\n\n", $line);
        }

        $this->assertSame("data: [DONE]\n\n", end($rawLines));
        $this->assertCount(count($events) + 1, $rawLines);

        // The leading status frame reports the model-loading phase verbatim.
        $this->assertSame(
            ['type' => 'status', 'phase' => 'model_loading', 'message' => 'Waiting for model response...'],
            $events[0],
        );
    }

    /**
     * The `[DONE]` sentinel is part of the documented stream contract, and it is
     * asymmetric: only a turn that finishes sends it. A turn that ends in an
     * error frame stops there, so a client must treat an error as terminal in
     * its own right rather than waiting for a terminator that never arrives.
     */
    public function test_only_a_finished_turn_is_terminated_with_done(): void
    {
        Queue::fake();

        // A turn that completes normally.
        CodeTalkerAgent::fake(['All done']);
        $persona = $this->makePersona();
        $service = $this->app->make(AiPersonaConversationService::class);

        $rawLines = [];
        $this->drainAndDecode($service->continueConversation($service->startConversation($persona), 'Hi'), $rawLines);

        $this->assertSame("data: [DONE]\n\n", end($rawLines));

        // A turn cut off by the max-duration guard.
        config()->set('code-talker.conversations.max_stream_seconds', 60);
        CodeTalkerAgent::fake(['Never finishes']);

        $timingOut = new class(
            $this->app->make(AgentFactory::class),
            $this->app->make(AiMemoryService::class),
            $this->app->make(ConversationUsageService::class),
            $this->app->make(RawExchangeContext::class),
            $this->app->make(AiSystemProviderConfigurator::class),
        ) extends AiPersonaConversationService {
            protected function streamElapsedSeconds(float $startedAt): float
            {
                return 9999.0;
            }
        };

        $rawLines = [];
        $events = $this->drainAndDecode(
            $timingOut->continueConversation($timingOut->startConversation($this->makePersona([], ['slug' => 'timeout-bot'])), 'Hi'),
            $rawLines,
        );

        $this->assertSame('max_stream_duration', end($events)['reason']);
        $this->assertStringNotContainsString('[DONE]', end($rawLines));

        // A turn that fails on the provider.
        $failing = $this->makePersona([], ['slug' => 'broken-bot']);
        $failing->aiSystem->forceFill(['provider' => 'not-a-real-provider'])->save();

        $rawLines = [];
        $events = $this->drainAndDecode(
            $service->continueConversation($service->startConversation($failing)->fresh(), 'Hi'),
            $rawLines,
        );

        $this->assertSame('provider_error', end($events)['reason']);
        $this->assertStringNotContainsString('[DONE]', end($rawLines));
    }

    public function test_tool_calls_run_through_the_registry_and_are_logged(): void
    {
        Queue::fake();
        Http::fake([
            'https://example.com/page' => Http::response(
                '<html><head><title>Hi</title></head><body><p>Body text.</p></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        CodeTalkerAgent::fake([
            new ToolCall('tool-1', 'fetch-web-page', ['url' => 'https://example.com/page']),
            'Summary after reading the page',
        ]);

        $persona = $this->makePersona(
            ['allowed_tools' => ['fetch-web-page']],
            ['tools_enabled' => true],
        );

        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Read the page'));

        $types = array_column($events, 'type');

        // The raw provider ToolCall/ToolResult events are never forwarded —
        // StreamTranslator doesn't know how to render their payloads — but a
        // tool_use_progress frame is, so the browser sees that a tool ran.
        $this->assertNotContains('tool_call', $types);
        $this->assertContains('tool_use_progress', $types);
        $this->assertSame(1, array_count_values($types)['message_start']);

        $toolProgress = collect($events)->firstWhere('type', 'tool_use_progress');
        $this->assertSame(['fetch-web-page'], $toolProgress['tools']);

        // usingToolPayloads() was never called — arguments/results (which may
        // carry whatever the model or a fetched page put in them) stay out of
        // the frame by default.
        $this->assertArrayNotHasKey('input', $toolProgress);
        collect($events)
            ->where('type', 'tool_use_progress')
            ->each(fn (array $event) => $this->assertArrayNotHasKey('output', $event));

        $text = collect($events)
            ->where('type', 'content_block_delta')
            ->pluck('delta.text')
            ->implode('');

        $this->assertSame('Summary after reading the page', $text);

        $response = AiLlmMessage::where('direction', 'response')->first();

        $this->assertSame(
            [['id' => 'tool-1', 'name' => 'fetch-web-page']],
            $response->response_data['tool_calls'],
        );

        $eventTypes = array_column($response->response_data['events'], 'type');
        $this->assertContains('tool_call', $eventTypes);
        $this->assertContains('tool_result', $eventTypes);
    }

    public function test_using_tool_payloads_includes_arguments_and_result_on_the_progress_frames(): void
    {
        Queue::fake();
        Http::fake([
            'https://example.com/page' => Http::response(
                '<html><head><title>Hi</title></head><body><p>Body text.</p></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        CodeTalkerAgent::fake([
            new ToolCall('tool-1', 'fetch-web-page', ['url' => 'https://example.com/page']),
            'Summary after reading the page',
        ]);

        $persona = $this->makePersona(
            ['allowed_tools' => ['fetch-web-page']],
            ['tools_enabled' => true],
        );

        $service = $this->app->make(AiPersonaConversationService::class)->usingToolPayloads();
        $conversation = $service->startConversation($persona);

        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Read the page'));

        $progressEvents = collect($events)->where('type', 'tool_use_progress')->values();

        $callFrame = $progressEvents->first(fn (array $event) => array_key_exists('input', $event));
        $this->assertNotNull($callFrame);
        $this->assertSame(['url' => 'https://example.com/page'], $callFrame['input']);

        $resultFrame = $progressEvents->first(fn (array $event) => array_key_exists('output', $event));
        $this->assertNotNull($resultFrame);
        $this->assertTrue($resultFrame['successful']);
    }

    public function test_a_tool_result_carrying_page_reload_emits_a_page_reload_frame(): void
    {
        Queue::fake();

        CodeTalkerServiceProvider::addToolDirectory(
            __DIR__ . '/../Fixtures/Tools',
            'Jvjvjv\\CodeTalker\\Tests\\Fixtures\\Tools\\',
        );

        CodeTalkerAgent::fake([
            new ToolCall('tool-1', 'page-reloading-test-tool', []),
            'Done, the page will reload.',
        ]);

        $persona = $this->makePersona(
            ['allowed_tools' => ['page-reloading-test-tool']],
            ['tools_enabled' => true],
        );

        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Do the thing'));

        $types = array_column($events, 'type');
        $this->assertContains('page_reload', $types);
        $this->assertContains('tool_use_progress', $types);
    }

    public function test_a_tool_result_without_page_reload_does_not_emit_the_frame(): void
    {
        Queue::fake();
        Http::fake([
            'https://example.com/page' => Http::response(
                '<html><head><title>Hi</title></head><body><p>Body text.</p></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        CodeTalkerAgent::fake([
            new ToolCall('tool-1', 'fetch-web-page', ['url' => 'https://example.com/page']),
            'Summary after reading the page',
        ]);

        $persona = $this->makePersona(
            ['allowed_tools' => ['fetch-web-page']],
            ['tools_enabled' => true],
        );

        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Read the page'));

        $this->assertNotContains('page_reload', array_column($events, 'type'));
    }

    public function test_a_runaway_stream_is_aborted_when_it_exceeds_the_max_duration(): void
    {
        Queue::fake();
        CodeTalkerAgent::fake(['This text should never fully stream to the browser']);

        config()->set('code-talker.conversations.max_stream_seconds', 60);

        $persona = $this->makePersona();

        // Override the elapsed-time source so the wall-clock guard trips
        // deterministically (on the very first checked event), without
        // depending on real streaming duration.
        $service = new class(
            $this->app->make(AgentFactory::class),
            $this->app->make(AiMemoryService::class),
            $this->app->make(ConversationUsageService::class),
            $this->app->make(RawExchangeContext::class),
            $this->app->make(AiSystemProviderConfigurator::class),
        ) extends AiPersonaConversationService {
            protected function streamElapsedSeconds(float $startedAt): float
            {
                return 9999.0;
            }
        };

        $conversation = $service->startConversation($persona);
        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Hi'));

        $error = collect($events)->firstWhere('type', 'error');
        $this->assertNotNull($error);
        $this->assertStringContainsString('maximum stream duration', $error['message']);
        // Distinct reason code so the frontend can identify this failure mode
        // without pattern-matching on message text (which coincidentally
        // contains "aborted", the same word used for a benign client abort).
        $this->assertSame('max_stream_duration', $error['reason']);

        $log = AiInteractionLog::first();
        $this->assertSame('error', $log->status->value);
        $this->assertStringContainsString('maximum stream duration', $log->error_message);
        $this->assertSame('max_stream_duration', $log->provider_metadata['error_reason']);

        // The turn is still recorded as a normal (if failed) attempt — the
        // guard stops the turn like a client abort rather than throwing, so
        // it no longer produces the legacy request-direction row with an
        // 'error' field, and it does produce a clean response row.
        $this->assertSame(1, AiLlmMessage::where('direction', 'response')->count());

        // Nothing streamed before the guard tripped on the very first event,
        // so there is no content to preserve — but the turn is still recorded,
        // flagged as interrupted, rather than leaving the user's message with
        // nothing beneath it.
        $message = AiConversationMessage::where('role', 'assistant')->first();
        $this->assertNotNull($message);
        $this->assertSame('', $message->content);
        $this->assertTrue($message->metadata['incomplete']);
        $this->assertSame('max_stream_duration', $message->metadata['incomplete_reason']);
    }

    public function test_the_max_duration_guard_preserves_partial_reasoning_content(): void
    {
        Queue::fake();
        CodeTalkerAgent::fake([]);

        // A gateway that streams several reasoning deltas and then never
        // finishes — simulating a model stuck deliberating without ever
        // producing an answer.
        $stuckReasoning = new class([]) extends FakeTextGateway {
            public function generateStreamStep(
                string $invocationId,
                TextProvider $provider,
                string $model,
                ?string $instructions,
                array $messages,
                array $tools,
                ?array $schema,
                ?TextGenerationOptions $options,
                ?int $timeout,
                StepContext $stepContext,
            ): Generator {
                yield (new StreamStart(uniqid('', true), $provider->name(), $model, time()))
                    ->withInvocationId($invocationId);

                foreach (['Thinking', ' about', ' this', ' for', ' way', ' too', ' long...'] as $chunk) {
                    yield (new ReasoningDelta(uniqid('', true), 'reasoning-1', $chunk, time()))
                        ->withInvocationId($invocationId);
                }

                // Never actually returns a StepResponse: the guard is expected
                // to cut this off before the generator completes on its own.
                throw new RuntimeException('generator should have been abandoned before this point');
            }
        };

        $manager = $this->app->make(AiManager::class);
        (Closure::bind(function () use ($stuckReasoning): void {
            $this->fakeAgentGateways[CodeTalkerAgent::class] = $stuckReasoning;
        }, $manager, $manager::class))();

        $persona = $this->makePersona();

        // Trip the guard only after a few reasoning deltas have streamed, so
        // there is real partial content to preserve.
        $service = new class(
            $this->app->make(AgentFactory::class),
            $this->app->make(AiMemoryService::class),
            $this->app->make(ConversationUsageService::class),
            $this->app->make(RawExchangeContext::class),
            $this->app->make(AiSystemProviderConfigurator::class),
        ) extends AiPersonaConversationService {
            private int $calls = 0;

            protected function streamElapsedSeconds(float $startedAt): float
            {
                return ++$this->calls > 3 ? 9999.0 : 0.0;
            }
        };

        $conversation = $service->startConversation($persona);
        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Hi'));

        $error = collect($events)->firstWhere('type', 'error');
        $this->assertNotNull($error);
        $this->assertSame('max_stream_duration', $error['reason']);

        $log = AiInteractionLog::first();
        $this->assertSame('error', $log->status->value);

        // The reasoning that streamed before the abort is not lost.
        $message = AiConversationMessage::where('role', 'assistant')->first();
        $this->assertNotNull($message);
        $this->assertSame('', $message->content);
        $this->assertStringContainsString('Thinking about', $message->reasoning_content);
    }

    public function test_the_max_duration_guard_resets_on_each_provider_request(): void
    {
        Queue::fake();
        Http::fake([
            'https://example.com/page' => Http::response(
                '<html><head><title>Hi</title></head><body><p>Body text.</p></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        // A tool-call turn makes two provider requests (the tool_calls step,
        // then the continuation after the tool result) within a single
        // continueConversation() call, each with its own StreamStart.
        CodeTalkerAgent::fake([
            new ToolCall('tool-1', 'fetch-web-page', ['url' => 'https://example.com/page']),
            'Summary after reading the page',
        ]);

        $persona = $this->makePersona(
            ['allowed_tools' => ['fetch-web-page']],
            ['tools_enabled' => true],
        );

        $service = new class(
            $this->app->make(AgentFactory::class),
            $this->app->make(AiMemoryService::class),
            $this->app->make(ConversationUsageService::class),
            $this->app->make(RawExchangeContext::class),
            $this->app->make(AiSystemProviderConfigurator::class),
        ) extends AiPersonaConversationService {
            /** @var array<int, float> */
            public array $seenStartedAt = [];

            protected function streamElapsedSeconds(float $startedAt): float
            {
                $this->seenStartedAt[] = $startedAt;

                return 0.0;
            }
        };

        $conversation = $service->startConversation($persona);
        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Read the page'));

        // No error: the guard never saw a full-turn-cumulative duration —
        // each step's requests were checked against a start time reset for
        // that step.
        $this->assertNull(collect($events)->firstWhere('type', 'error'));

        // The clock the guard checks against changed between the tool_calls
        // step and the continuation step, proving the reset actually ran
        // rather than checking every event against the original turn start.
        $this->assertGreaterThan(1, count(array_unique($service->seenStartedAt)));
    }

    /**
     * A service whose cancellation check fires once the given number of stream
     * events has been consumed — the browser hanging up mid-turn, made
     * deterministic. The guard is consulted at the top of each iteration, so
     * `$events` events are processed before the loop breaks.
     */
    private function abortingAfter(int $events): AiPersonaConversationService
    {
        $checks = 0;

        return $this->app->make(AiPersonaConversationService::class)
            ->usingCancellationCheck(static function () use (&$checks, $events): bool {
                return $checks++ >= $events;
            });
    }

    public function test_a_client_abort_before_any_content_still_records_an_interrupted_message(): void
    {
        Queue::fake();
        CodeTalkerAgent::fake(['This turn is cancelled by the browser mid-stream']);

        $persona = $this->makePersona();

        // Abort with only the StreamStart consumed: the model was still
        // processing the prompt and had emitted nothing. This used to persist
        // nothing at all, so the user's message sat in the transcript with no
        // reply beneath it and no record that a turn had ever run.
        $service = $this->abortingAfter(1);

        $conversation = $service->startConversation($persona);
        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Hi'));

        // A client abort is a clean stop, not a provider failure: no error
        // event is emitted (and by then the browser is gone anyway).
        $this->assertNull(collect($events)->firstWhere('type', 'error'));

        // Only one attempt runs — the abort short-circuits any continuation loop.
        $this->assertSame(1, AiLlmMessage::where('direction', 'request')->count());
        $this->assertSame(1, AiLlmMessage::where('direction', 'response')->count());

        // The turn never finished, so it is not reported as one that did.
        $response = AiLlmMessage::where('direction', 'response')->first();
        $this->assertSame('incomplete', $response->response_data['stop_reason']);

        $log = AiInteractionLog::first();
        $this->assertNotNull($log);
        $this->assertSame('aborted', $log->status->value);
        $this->assertSame('client_aborted', $log->provider_metadata['error_reason']);

        // The interrupted reply is visible in the transcript, so the host can
        // render "this reply was interrupted" instead of silence.
        $message = AiConversationMessage::where('role', 'assistant')->first();
        $this->assertNotNull($message);
        $this->assertSame('', $message->content);
        $this->assertTrue($message->metadata['incomplete']);
        $this->assertSame('client_aborted', $message->metadata['incomplete_reason']);

        Queue::assertNotPushed(ProcessAiMemoryJob::class);
    }

    public function test_a_client_abort_after_a_tool_call_persists_the_tool_call(): void
    {
        Queue::fake();
        Http::fake([
            'https://example.com/page' => Http::response(
                '<html><head><title>Hi</title></head><body><p>Body text.</p></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        CodeTalkerAgent::fake([
            new ToolCall('tool-1', 'fetch-web-page', ['url' => 'https://example.com/page']),
            'Summary after reading the page',
        ]);

        $persona = $this->makePersona(
            ['allowed_tools' => ['fetch-web-page']],
            ['tools_enabled' => true],
        );

        // Abort with StreamStart and the ToolCall consumed. The tool may well
        // have run and changed state on the host's side; dropping the turn
        // would leave the next turn's history with no record the call was ever
        // made, and the model free to contradict itself about it.
        $service = $this->abortingAfter(2);

        $conversation = $service->startConversation($persona);
        $this->drainAndDecode($service->continueConversation($conversation, 'Read the page'));

        $message = AiConversationMessage::where('role', 'assistant')->first();
        $this->assertNotNull($message);
        $this->assertSame('', $message->content);
        $this->assertSame('fetch-web-page', $message->tool_calls[0]['name']);
        $this->assertTrue($message->metadata['incomplete']);

        $this->assertSame('aborted', AiInteractionLog::first()->status->value);
    }

    public function test_a_client_abort_after_a_text_delta_persists_the_partial_text(): void
    {
        Queue::fake();
        CodeTalkerAgent::fake(['This turn is cancelled by the browser mid-stream']);

        $persona = $this->makePersona();

        // StreamStart, TextStart, then the first TextDelta.
        $service = $this->abortingAfter(3);

        $conversation = $service->startConversation($persona);
        $this->drainAndDecode($service->continueConversation($conversation, 'Hi'));

        $message = AiConversationMessage::where('role', 'assistant')->first();
        $this->assertNotNull($message);
        $this->assertSame('This', $message->content);
        $this->assertTrue($message->metadata['incomplete']);
        $this->assertSame('client_aborted', $message->metadata['incomplete_reason']);
    }

    public function test_a_completed_turn_is_not_flagged_as_interrupted(): void
    {
        Queue::fake();
        CodeTalkerAgent::fake(['All done here']);

        $persona = $this->makePersona();
        $service = $this->app->make(AiPersonaConversationService::class);

        $conversation = $service->startConversation($persona);
        $this->drainAndDecode($service->continueConversation($conversation, 'Hi'));

        $message = AiConversationMessage::where('role', 'assistant')->first();
        $this->assertSame('All done here', $message->content);
        $this->assertFalse($message->metadata['incomplete']);
        $this->assertSame('success', AiInteractionLog::first()->status->value);
    }

    public function test_a_non_recoverable_provider_error_event_fails_the_turn_instead_of_logging_success(): void
    {
        Queue::fake();

        $persona = $this->makePersona();
        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        // Enable faked mode, then swap in a gateway that emits a non-recoverable
        // error event mid-stream — mirroring LM Studio returning HTTP 200 with an
        // SSE "event: error" (e.g. "Context size has been exceeded.").
        CodeTalkerAgent::fake([]);

        $erroring = new class([]) extends FakeTextGateway {
            public function generateStreamStep(
                string $invocationId,
                TextProvider $provider,
                string $model,
                ?string $instructions,
                array $messages,
                array $tools,
                ?array $schema,
                ?TextGenerationOptions $options,
                ?int $timeout,
                StepContext $stepContext,
            ): Generator {
                yield (new StreamStart(uniqid('', true), $provider->name(), $model, time()))
                    ->withInvocationId($invocationId);

                yield (new Error(uniqid('', true), 'unknown_error', 'Context size has been exceeded.', false, time()))
                    ->withInvocationId($invocationId);

                return new StepResponse('', [], FinishReason::Stop, new Usage, new Meta($provider->name(), $model));
            }
        };

        $manager = $this->app->make(AiManager::class);
        (Closure::bind(function () use ($erroring): void {
            $this->fakeAgentGateways[CodeTalkerAgent::class] = $erroring;
        }, $manager, $manager::class))();

        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Hi'));

        $error = collect($events)->firstWhere('type', 'error');
        $this->assertNotNull($error);
        $this->assertStringContainsString('Context size has been exceeded', $error['message']);
        $this->assertSame('provider_error', $error['reason']);

        $log = AiInteractionLog::first();
        $this->assertSame('error', $log->status->value);
        $this->assertStringContainsString('Context size has been exceeded', $log->error_message);

        // No false success: no assistant message and no success response record.
        $this->assertNull(AiConversationMessage::where('role', 'assistant')->first());
        $this->assertSame(0, AiLlmMessage::where('direction', 'response')->count());
    }

    public function test_provider_failures_emit_the_legacy_error_event_and_log_the_failure(): void
    {
        Queue::fake();

        $persona = $this->makePersona(['provider' => 'anthropic']);
        $persona->aiSystem->forceFill(['provider' => 'not-a-real-provider'])->save();

        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        $rawLines = [];
        $events = $this->drainAndDecode($service->continueConversation($conversation->fresh(), 'Hi'), $rawLines);

        $error = collect($events)->firstWhere('type', 'error');
        $this->assertNotNull($error);
        $this->assertStringContainsString('Unsupported AI provider', $error['message']);
        $this->assertSame('provider_error', $error['reason']);

        $log = AiInteractionLog::first();
        $this->assertSame('error', $log->status->value);
        $this->assertStringContainsString('Unsupported AI provider', $log->error_message);

        $request = AiLlmMessage::where('direction', 'request')->first();
        $this->assertArrayHasKey('error', $request->request_data);

        Queue::assertNotPushed(ProcessAiMemoryJob::class);
    }

    /**
     * Install a gateway that emits heartbeats between its text deltas — a
     * model that is slow to produce tokens rather than one that has stopped.
     */
    private function fakeHeartbeatingGateway(int $beats): void
    {
        CodeTalkerAgent::fake([]);

        $gateway = new class([], $beats) extends FakeTextGateway {
            public function __construct(array $responses, private int $beats)
            {
                parent::__construct($responses);
            }

            public function generateStreamStep(
                string $invocationId,
                TextProvider $provider,
                string $model,
                ?string $instructions,
                array $messages,
                array $tools,
                ?array $schema,
                ?TextGenerationOptions $options,
                ?int $timeout,
                StepContext $stepContext,
            ): Generator {
                yield (new StreamStart(uniqid('', true), $provider->name(), $model, time()))
                    ->withInvocationId($invocationId);

                for ($i = 0; $i < $this->beats; $i++) {
                    yield (new Heartbeat(uniqid('', true), time()))->withInvocationId($invocationId);
                }

                yield (new TextDelta(uniqid('', true), 'm1', 'Done', time()))
                    ->withInvocationId($invocationId);

                yield (new StreamEnd(uniqid('', true), 'stop', new Usage(), time()))
                    ->withInvocationId($invocationId);

                return new StepResponse(
                    'Done', [], FinishReason::Stop, new Usage(), new Meta($provider->name(), $model),
                );
            }
        };

        $manager = $this->app->make(AiManager::class);
        (Closure::bind(function () use ($gateway): void {
            $this->fakeAgentGateways[CodeTalkerAgent::class] = $gateway;
        }, $manager, $manager::class))();
    }

    public function test_a_heartbeat_reaches_the_browser_but_never_the_stored_events(): void
    {
        Queue::fake();
        $this->fakeHeartbeatingGateway(beats: 3);

        $persona = $this->makePersona();
        $service = $this->app->make(AiPersonaConversationService::class);

        $conversation = $service->startConversation($persona);
        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Hi'));

        $this->assertSame(3, count(array_filter($events, fn ($e) => ($e['type'] ?? null) === 'heartbeat')));

        // The stored event log is a record of what the model did, not of how
        // long it took to do it.
        $logged = AiLlmMessage::where('direction', 'response')->first()->response_data['events'];
        $this->assertNotContains('heartbeat', array_column($logged, 'type'));

        // The answer itself is unaffected.
        $this->assertSame('Done', AiConversationMessage::where('role', 'assistant')->first()->content);
    }

    public function test_the_max_duration_guard_trips_on_a_heartbeat_with_no_provider_event(): void
    {
        Queue::fake();
        config()->set('code-talker.conversations.max_stream_seconds', 60);
        $this->fakeHeartbeatingGateway(beats: 3);

        $persona = $this->makePersona();

        // Elapsed time only goes over budget after the StreamStart, so the
        // guard has nothing but heartbeats to trip on.
        $service = new class(
            $this->app->make(AgentFactory::class),
            $this->app->make(AiMemoryService::class),
            $this->app->make(ConversationUsageService::class),
            $this->app->make(RawExchangeContext::class),
            $this->app->make(AiSystemProviderConfigurator::class),
        ) extends AiPersonaConversationService {
            private int $calls = 0;

            protected function streamElapsedSeconds(float $startedAt): float
            {
                return ++$this->calls > 1 ? 9999.0 : 0.0;
            }
        };

        $conversation = $service->startConversation($persona);
        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Hi'));

        $error = collect($events)->firstWhere('type', 'error');
        $this->assertNotNull($error);
        $this->assertSame('max_stream_duration', $error['reason']);
    }

    public function test_dispatching_a_turn_queues_a_job_against_a_new_run(): void
    {
        Queue::fake();

        $persona = $this->makePersona();
        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        $run = $service->dispatchTurn($conversation, 'Hi there');

        $this->assertSame(AiTurnRunStatus::Queued, $run->status);
        $this->assertSame('Hi there', $run->prompt);
        $this->assertNotEmpty($run->public_id);

        Queue::assertPushed(
            RunConversationTurnJob::class,
            fn (RunConversationTurnJob $job): bool => $job->turnRunId === $run->id,
        );
    }

    public function test_resuming_a_turn_streams_its_stored_events(): void
    {
        Queue::fake();
        config()->set('code-talker.turns.poll_interval_ms', 1);

        $persona = $this->makePersona();
        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        $run = $service->dispatchTurn($conversation, 'Hi');

        $store = $this->app->make(TurnRunStore::class);
        $store->markRunning($run);
        $store->append($run, ['type' => 'content_block_delta', 'delta' => ['text' => 'Hi']]);
        $store->finish($run, AiTurnRunStatus::Completed);

        $events = iterator_to_array($service->resumeTurn($run), false);

        $this->assertSame(['content_block_delta'], array_column($events, 'type'));
    }

    public function test_cancelling_a_turn_marks_it_for_the_worker(): void
    {
        Queue::fake();

        $persona = $this->makePersona();
        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        $run = $service->dispatchTurn($conversation, 'Hi');
        $service->cancelTurn($run);

        $this->assertNotNull($run->fresh()->cancel_requested_at);
    }
}

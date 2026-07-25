<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Jobs\ProcessAiMemoryJob;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiInteractionLog;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiChatBotConversationService;
use Jvjvjv\CodeTalker\Services\AiMemoryService;
use Jvjvjv\CodeTalker\Services\ConversationUsageService;
use Jvjvjv\CodeTalker\Services\LaravelAi\AgentFactory;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\LaravelAi\CodeTalkerAgent;
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
use Laravel\Ai\Streaming\Events\StreamStart;
use RuntimeException;

class AiChatBotConversationServiceTest extends TestCase
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

    private function makeBot(array $systemAttributes = [], array $botAttributes = []): AiChatBot
    {
        $system = AiSystem::create(array_merge([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ], $systemAttributes));

        return AiChatBot::create(array_merge([
            'ai_system_id' => $system->id,
            'name' => 'Test Bot',
            'slug' => 'test-bot',
            'prompt_template' => 'You are {{bot_name}}.',
            'is_active' => true,
        ], $botAttributes));
    }

    /**
     * @return array<int, array<string, mixed>> decoded JSON events, excluding [DONE]
     */
    private function drainAndDecode(iterable $stream, array &$rawLines = []): array
    {
        $events = [];

        foreach ($stream as $line) {
            $rawLines[] = $line;

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

        $bot = $this->makeBot();
        $service = $this->app->make(AiChatBotConversationService::class);
        $conversation = $service->startConversation($bot);

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

        $bot = $this->makeBot();
        $service = $this->app->make(AiChatBotConversationService::class);
        $conversation = $service->startConversation($bot);

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

        $bot = $this->makeBot(
            ['allowed_tools' => ['fetch-web-page']],
            ['tools_enabled' => true],
        );

        $service = $this->app->make(AiChatBotConversationService::class);
        $conversation = $service->startConversation($bot);

        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Read the page'));

        $types = array_column($events, 'type');

        // Tool activity is never forwarded to the browser.
        $this->assertNotContains('tool_call', $types);
        $this->assertSame(1, array_count_values($types)['message_start']);

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

    public function test_a_runaway_stream_is_aborted_when_it_exceeds_the_max_duration(): void
    {
        Queue::fake();
        CodeTalkerAgent::fake(['This text should never fully stream to the browser']);

        config()->set('code-talker.conversations.max_stream_seconds', 60);

        $bot = $this->makeBot();

        // Override the elapsed-time source so the wall-clock guard trips
        // deterministically (on the very first checked event), without
        // depending on real streaming duration.
        $service = new class(
            $this->app->make(AgentFactory::class),
            $this->app->make(AiMemoryService::class),
            $this->app->make(ConversationUsageService::class),
            $this->app->make(RawExchangeContext::class),
            $this->app->make(AiSystemProviderConfigurator::class),
        ) extends AiChatBotConversationService {
            protected function streamElapsedSeconds(float $startedAt): float
            {
                return 9999.0;
            }
        };

        $conversation = $service->startConversation($bot);
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
        // so there is no content to preserve.
        $this->assertNull(AiConversationMessage::where('role', 'assistant')->first());
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

        $bot = $this->makeBot();

        // Trip the guard only after a few reasoning deltas have streamed, so
        // there is real partial content to preserve.
        $service = new class(
            $this->app->make(AgentFactory::class),
            $this->app->make(AiMemoryService::class),
            $this->app->make(ConversationUsageService::class),
            $this->app->make(RawExchangeContext::class),
            $this->app->make(AiSystemProviderConfigurator::class),
        ) extends AiChatBotConversationService {
            private int $calls = 0;

            protected function streamElapsedSeconds(float $startedAt): float
            {
                return ++$this->calls > 3 ? 9999.0 : 0.0;
            }
        };

        $conversation = $service->startConversation($bot);
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

        $bot = $this->makeBot(
            ['allowed_tools' => ['fetch-web-page']],
            ['tools_enabled' => true],
        );

        $service = new class(
            $this->app->make(AgentFactory::class),
            $this->app->make(AiMemoryService::class),
            $this->app->make(ConversationUsageService::class),
            $this->app->make(RawExchangeContext::class),
            $this->app->make(AiSystemProviderConfigurator::class),
        ) extends AiChatBotConversationService {
            /** @var array<int, float> */
            public array $seenStartedAt = [];

            protected function streamElapsedSeconds(float $startedAt): float
            {
                $this->seenStartedAt[] = $startedAt;

                return 0.0;
            }
        };

        $conversation = $service->startConversation($bot);
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

    public function test_a_client_abort_stops_the_turn_and_persists_the_partial_response(): void
    {
        Queue::fake();
        CodeTalkerAgent::fake(['This turn is cancelled by the browser mid-stream']);

        $bot = $this->makeBot();

        // Simulate the browser hanging up (Cancel button / ESC): the abort guard
        // trips after the first stream event so a partial response is captured.
        $service = new class(
            $this->app->make(AgentFactory::class),
            $this->app->make(AiMemoryService::class),
            $this->app->make(ConversationUsageService::class),
            $this->app->make(RawExchangeContext::class),
            $this->app->make(AiSystemProviderConfigurator::class),
        ) extends AiChatBotConversationService {
            private int $checks = 0;

            protected function clientAborted(): bool
            {
                return $this->checks++ > 0;
            }
        };

        $conversation = $service->startConversation($bot);
        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Hi'));

        // A client abort is a clean stop, not a failure: no error event is emitted.
        $this->assertNull(collect($events)->firstWhere('type', 'error'));

        // The turn is still recorded (request + partial response), and the
        // interaction log is not marked as an error.
        $this->assertSame(1, AiLlmMessage::where('direction', 'response')->count());

        $log = AiInteractionLog::first();
        $this->assertNotNull($log);
        $this->assertSame('success', $log->status->value);

        // Only one attempt runs — the abort short-circuits any continuation loop.
        $this->assertSame(1, AiLlmMessage::where('direction', 'request')->count());

        Queue::assertNotPushed(ProcessAiMemoryJob::class);
    }

    public function test_a_non_recoverable_provider_error_event_fails_the_turn_instead_of_logging_success(): void
    {
        Queue::fake();

        $bot = $this->makeBot();
        $service = $this->app->make(AiChatBotConversationService::class);
        $conversation = $service->startConversation($bot);

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

        $bot = $this->makeBot(['provider' => 'anthropic']);
        $bot->aiSystem->forceFill(['provider' => 'not-a-real-provider'])->save();

        $service = $this->app->make(AiChatBotConversationService::class);
        $conversation = $service->startConversation($bot);

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
}

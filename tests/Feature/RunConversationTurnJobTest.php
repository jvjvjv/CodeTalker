<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Jobs\RunConversationTurnJob;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiPersona;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Models\AiTurnRun;
use Jvjvjv\CodeTalker\Services\AiPersonaConversationService;
use Jvjvjv\CodeTalker\Services\Conversation\TurnRunStore;
use Jvjvjv\CodeTalker\Services\LaravelAi\CodeTalkerAgent;
use Jvjvjv\CodeTalker\Services\LaravelAi\Streaming\Heartbeat;
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
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;

class RunConversationTurnJobTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasColumn('ai_conversations', 'uuid')) {
            Schema::table('ai_conversations', function ($table): void {
                $table->string('uuid')->nullable();
            });
        }
    }

    private function persona(): AiPersona
    {
        $system = AiSystem::create([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);

        return AiPersona::create([
            'ai_system_id' => $system->id,
            'name' => 'Test Bot',
            'slug' => 'test-bot',
            'prompt_template' => 'You are {{persona_name}}.',
            'is_active' => true,
        ]);
    }

    public function test_the_job_records_every_event_and_completes_the_run(): void
    {
        Queue::fake();
        CodeTalkerAgent::fake(['Hello there']);

        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($this->persona());

        $run = $this->app->make(TurnRunStore::class)->open($conversation, 'Hi');

        $this->app->make(RunConversationTurnJob::class, ['turnRunId' => $run->id])
            ->handle($service, $this->app->make(TurnRunStore::class));

        $run->refresh();
        $this->assertSame(AiTurnRunStatus::Completed, $run->status);
        $this->assertNotNull($run->finished_at);

        $types = $run->events()->get()->pluck('payload.type')->all();
        $this->assertContains('content_block_delta', $types);
        $this->assertContains('message_stop', $types);

        // Sequences are contiguous from 1, which is what a resuming reader
        // relies on to know it missed nothing.
        $this->assertSame(range(1, $run->events()->count()), $run->events()->pluck('sequence')->all());

        // The turn itself behaved exactly as the synchronous path does.
        $this->assertSame('Hello there', AiConversationMessage::where('role', 'assistant')->first()->content);
    }

    public function test_a_cancelled_run_stops_and_is_marked_cancelled(): void
    {
        Queue::fake();
        CodeTalkerAgent::fake(['This answer is cancelled part way through']);

        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($this->persona());

        $store = $this->app->make(TurnRunStore::class);
        $run = $store->open($conversation, 'Hi');
        $store->requestCancel($run);

        $this->app->make(RunConversationTurnJob::class, ['turnRunId' => $run->id])
            ->handle($service, $this->app->make(TurnRunStore::class));

        $this->assertSame(AiTurnRunStatus::Cancelled, $run->fresh()->status);

        // 0.15.0's recorder keeps whatever the turn produced, flagged.
        $message = AiConversationMessage::where('role', 'assistant')->first();
        $this->assertNotNull($message);
        $this->assertTrue($message->metadata['incomplete']);
    }

    /**
     * Enable faked mode, then swap in the given gateway — the same pattern
     * AiPersonaConversationServiceTest uses for its stream-shaped fakes.
     */
    private function installGateway(FakeTextGateway $gateway): void
    {
        CodeTalkerAgent::fake([]);

        $manager = $this->app->make(AiManager::class);
        (Closure::bind(function () use ($gateway): void {
            $this->fakeAgentGateways[CodeTalkerAgent::class] = $gateway;
        }, $manager, $manager::class))();
    }

    public function test_heartbeats_are_consumed_and_never_stored_as_turn_events(): void
    {
        Queue::fake();

        // A gateway that beats between its text deltas — a model slow to
        // produce tokens rather than one that has stopped.
        $this->installGateway(new class([]) extends FakeTextGateway {
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

                yield (new TextDelta(uniqid('', true), 'm1', 'Hel', time()))
                    ->withInvocationId($invocationId);

                for ($i = 0; $i < 3; $i++) {
                    yield (new Heartbeat(uniqid('', true), time()))->withInvocationId($invocationId);
                }

                yield (new TextDelta(uniqid('', true), 'm1', 'lo', time()))
                    ->withInvocationId($invocationId);

                yield (new StreamEnd(uniqid('', true), 'stop', new Usage(), time()))
                    ->withInvocationId($invocationId);

                return new StepResponse(
                    'Hello', [], FinishReason::Stop, new Usage(), new Meta($provider->name(), $model),
                );
            }
        });

        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($this->persona());
        $run = $this->app->make(TurnRunStore::class)->open($conversation, 'Hi');

        $this->app->make(RunConversationTurnJob::class, ['turnRunId' => $run->id])
            ->handle($service, $this->app->make(TurnRunStore::class));

        $run->refresh();
        $this->assertSame(AiTurnRunStatus::Completed, $run->status);

        // No beat reached the store — SseFrameEncoder never transmits one, so
        // a stored beat would burn a sequence a reconnecting reader never saw.
        $types = $run->events()->get()->pluck('payload.type');
        $this->assertNotContains('heartbeat', $types->all());
        $this->assertContains('content_block_delta', $types->all());

        // And the text events hold contiguous sequences with no gaps.
        $this->assertSame(range(1, $run->events()->count()), $run->events()->pluck('sequence')->all());
    }

    public function test_an_in_stream_provider_error_finishes_the_run_failed(): void
    {
        Queue::fake();

        // Mirrors LM Studio returning HTTP 200 then a non-recoverable SSE
        // "event: error" — continueConversation() converts it into a terminal
        // error event rather than throwing, so the generator ends normally.
        $this->installGateway(new class([]) extends FakeTextGateway {
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

                return new StepResponse('', [], FinishReason::Stop, new Usage(), new Meta($provider->name(), $model));
            }
        });

        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($this->persona());
        $run = $this->app->make(TurnRunStore::class)->open($conversation, 'Hi');

        $this->app->make(RunConversationTurnJob::class, ['turnRunId' => $run->id])
            ->handle($service, $this->app->make(TurnRunStore::class));

        $run->refresh();
        $this->assertSame(AiTurnRunStatus::Failed, $run->status);
        $this->assertStringContainsString('Context size has been exceeded', $run->error_message);

        // The terminal error event itself is stored, so a reader replaying the
        // run sees the same failure the run's status reports.
        $this->assertContains('error', $run->events()->get()->pluck('payload.type')->all());
    }

    public function test_a_failed_job_marks_the_run_failed_so_a_reader_stops_waiting(): void
    {
        Queue::fake();

        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($this->persona());
        $run = $this->app->make(TurnRunStore::class)->open($conversation, 'Hi');

        $this->app->make(RunConversationTurnJob::class, ['turnRunId' => $run->id])
            ->failed(new \RuntimeException('worker died'));

        $run->refresh();
        $this->assertSame(AiTurnRunStatus::Failed, $run->status);
        $this->assertSame('worker died', $run->error_message);
    }
}

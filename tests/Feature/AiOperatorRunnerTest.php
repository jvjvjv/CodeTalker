<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Enums\AiConversationStatus;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Models\AiInteractionLog;
use Jvjvjv\CodeTalker\Models\AiOperator;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\LaravelAi\CodeTalkerAgent;
use Jvjvjv\CodeTalker\Services\Operator\AiOperatorRunner;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Closure;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use RuntimeException;

class AiOperatorRunnerTest extends TestCase
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

    private function makeOperator(array $overrides = []): AiOperator
    {
        $system = AiSystem::create([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);

        return AiOperator::create(array_merge([
            'ai_system_id' => $system->id,
            'name' => 'Test Operator',
            'slug' => 'test-operator',
            'prompt_template' => 'A new order was placed: {{order.total}}.',
            'is_active' => true,
        ], $overrides));
    }

    public function test_a_run_creates_a_conversation_and_records_one_llm_message(): void
    {
        CodeTalkerAgent::fake(['Order processed.']);

        $operator = $this->makeOperator();

        $conversation = $this->app->make(AiOperatorRunner::class)->run($operator, ['order' => ['total' => 42]]);

        $this->assertSame($operator->id, $conversation->ai_operator_id);
        $this->assertNull($conversation->ai_persona_id);
        $this->assertSame('operator:test-operator', $conversation->feature);
        $this->assertSame(AiConversationStatus::Completed, $conversation->status);

        $this->assertSame(
            ['request', 'response'],
            AiLlmMessage::orderBy('id')->pluck('direction')->all(),
        );

        $request = AiLlmMessage::where('direction', 'request')->first();
        $requestMessages = $request->request_data['messages'];
        $this->assertSame('A new order was placed: 42.', end($requestMessages)['content']);

        $response = AiLlmMessage::where('direction', 'response')->first();
        $this->assertSame('Order processed.', $response->response_data['text']);

        // The run's cost lands on AiInteractionLog / the conversation's usage
        // columns exactly the way a persona turn's does — ConversationUsageService
        // has no operator-specific code path, so this proves it "just works".
        $log = AiInteractionLog::first();
        $this->assertSame('success', $log->status->value);
        $this->assertSame($conversation->id, $log->ai_conversation_id);
        $this->assertNotNull($conversation->fresh()->usage_synced_at);
    }

    public function test_an_unresolved_placeholder_fails_before_any_provider_call(): void
    {
        CodeTalkerAgent::fake(['should never be reached']);

        $operator = $this->makeOperator();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('order.total');

        try {
            $this->app->make(AiOperatorRunner::class)->run($operator, []);
        } finally {
            $this->assertSame(0, AiLlmMessage::count());
            CodeTalkerAgent::assertNeverPrompted();
        }
    }

    public function test_a_non_stop_finish_reason_fails_the_run_instead_of_being_accepted(): void
    {
        CodeTalkerAgent::fake([]);

        // A gateway that finishes on the token limit rather than a clean stop.
        $truncated = new class([]) extends FakeTextGateway {
            public function generateTextStep(
                TextProvider $provider,
                string $model,
                ?string $instructions,
                array $messages,
                array $tools,
                ?array $schema,
                ?TextGenerationOptions $options,
                ?int $timeout,
                StepContext $stepContext,
            ): StepResponse {
                return new StepResponse('Cut off mid-', [], FinishReason::Length, new Usage, new Meta($provider->name(), $model));
            }
        };

        $manager = $this->app->make(AiManager::class);
        (Closure::bind(function () use ($truncated): void {
            $this->fakeAgentGateways[CodeTalkerAgent::class] = $truncated;
        }, $manager, $manager::class))();

        $operator = $this->makeOperator();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('length');

        try {
            $this->app->make(AiOperatorRunner::class)->run($operator, ['order' => ['total' => 42]]);
        } finally {
            // Still logged: the response row is recorded before the failure is
            // thrown, so the truncated output is not lost, just not accepted.
            $this->assertSame(1, AiLlmMessage::where('direction', 'response')->count());
        }
    }
}

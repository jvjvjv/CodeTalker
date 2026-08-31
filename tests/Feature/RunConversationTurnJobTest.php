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
use Jvjvjv\CodeTalker\Tests\TestCase;

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

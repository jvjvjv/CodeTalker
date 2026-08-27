<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Enums\AiConversationStatus;
use Jvjvjv\CodeTalker\Jobs\ProcessAiMemoryJob;
use Jvjvjv\CodeTalker\Models\AiPersona;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Tests\TestCase;

class CompleteIdleConversationsCommandTest extends TestCase
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

    private function makePersona(): AiPersona
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

    private function makeConversation(AiPersona $persona, array $attributes = []): AiConversation
    {
        return AiConversation::create(array_merge([
            'ai_system_id' => $persona->ai_system_id,
            'ai_persona_id' => $persona->id,
            'feature' => 'persona:test-bot',
            'status' => AiConversationStatus::Active,
        ], $attributes));
    }

    private function addMessage(AiConversation $conversation, string $role, \DateTimeInterface $at): void
    {
        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => $role,
            'content' => 'Hello',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    public function test_it_completes_conversations_idle_past_the_window_and_queues_memory_extraction(): void
    {
        Queue::fake();

        $persona = $this->makePersona();
        $conversation = $this->makeConversation($persona);
        $this->addMessage($conversation, 'user', now()->subMinutes(90));
        $this->addMessage($conversation, 'assistant', now()->subMinutes(89));

        $this->artisan('ai:complete-idle-conversations', ['--minutes' => 30])
            ->assertSuccessful();

        $this->assertSame(
            AiConversationStatus::Completed,
            $conversation->fresh()->status,
        );

        Queue::assertPushed(ProcessAiMemoryJob::class);
    }

    public function test_it_leaves_recently_active_conversations_alone(): void
    {
        Queue::fake();

        $persona = $this->makePersona();
        $conversation = $this->makeConversation($persona);
        $this->addMessage($conversation, 'user', now()->subMinutes(90));
        $this->addMessage($conversation, 'assistant', now()->subMinutes(2));

        $this->artisan('ai:complete-idle-conversations', ['--minutes' => 30])
            ->assertSuccessful();

        $this->assertSame(
            AiConversationStatus::Active,
            $conversation->fresh()->status,
        );

        Queue::assertNotPushed(ProcessAiMemoryJob::class);
    }

    public function test_it_ignores_conversations_with_no_messages(): void
    {
        Queue::fake();

        $persona = $this->makePersona();
        $conversation = $this->makeConversation($persona, ['created_at' => now()->subDay()]);

        $this->artisan('ai:complete-idle-conversations', ['--minutes' => 30])
            ->assertSuccessful();

        $this->assertSame(
            AiConversationStatus::Active,
            $conversation->fresh()->status,
        );

        Queue::assertNotPushed(ProcessAiMemoryJob::class);
    }

    public function test_dry_run_reports_without_changing_anything(): void
    {
        Queue::fake();

        $persona = $this->makePersona();
        $conversation = $this->makeConversation($persona);
        $this->addMessage($conversation, 'user', now()->subMinutes(90));

        $this->artisan('ai:complete-idle-conversations', ['--minutes' => 30, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(
            AiConversationStatus::Active,
            $conversation->fresh()->status,
        );

        Queue::assertNotPushed(ProcessAiMemoryJob::class);
    }
}

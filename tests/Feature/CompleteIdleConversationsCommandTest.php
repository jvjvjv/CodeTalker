<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
        // Extraction needs someone to remember things about.
        $conversation = $this->makeConversation($persona, ['visitor_email' => 'visitor@example.com']);
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
    /**
     * Each update drops the row out of the query being paged. Offset paging
     * (each()) would then skip a chunk's worth of rows per page; paging by id
     * does not, so a backlog larger than one chunk is completed in one run.
     */
    public function test_a_backlog_larger_than_one_chunk_is_completed_in_one_run(): void
    {
        Queue::fake();

        $persona = $this->makePersona();
        $idleAt = now()->subMinutes(90);

        foreach (array_chunk(range(1, 1500), 250) as $batch) {
            $conversationIds = [];

            foreach ($batch as $_) {
                $conversationIds[] = DB::table('ai_conversations')->insertGetId([
                    'ai_system_id' => $persona->ai_system_id,
                    'ai_persona_id' => $persona->id,
                    'feature' => 'persona:test-bot',
                    'status' => AiConversationStatus::Active->value,
                    'created_at' => $idleAt,
                    'updated_at' => $idleAt,
                ]);
            }

            DB::table('ai_conversation_messages')->insert(array_map(static fn (int $id): array => [
                'ai_conversation_id' => $id,
                'role' => 'user',
                'content' => 'Hello',
                'created_at' => $idleAt,
            ], $conversationIds));
        }

        $this->artisan('ai:complete-idle-conversations', ['--minutes' => 30])
            ->expectsOutputToContain('Completed 1500 idle conversation(s)')
            ->assertSuccessful();

        $this->assertSame(0, AiConversation::where('status', AiConversationStatus::Active->value)->count());
        $this->assertSame(1500, AiConversation::where('status', AiConversationStatus::Completed->value)->count());
    }

    /**
     * A negative window would put the cutoff in the future and make every
     * conversation look idle, including one mid-reply.
     */
    public function test_a_negative_idle_timeout_does_not_complete_live_conversations(): void
    {
        Queue::fake();
        config()->set('code-talker.conversations.idle_timeout_minutes', -5);

        $persona = $this->makePersona();
        $conversation = $this->makeConversation($persona);
        $this->addMessage($conversation, 'user', now()->addMinute());

        $this->artisan('ai:complete-idle-conversations')
            ->expectsOutputToContain('for 0 minute(s)')
            ->assertSuccessful();

        $this->assertSame(AiConversationStatus::Active, $conversation->fresh()->status);
    }
}

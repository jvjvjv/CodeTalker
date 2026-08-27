<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Models\AiPersona;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiPersonaConversationService;
use Jvjvjv\CodeTalker\Services\ChatBot\ChatBotPresenter;
use Jvjvjv\CodeTalker\Services\ChatBot\SseFrameEncoder;
use Jvjvjv\CodeTalker\Tests\TestCase;
use RuntimeException;

class ChatTurnLibraryTest extends TestCase
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

    private function makePersona(array $attributes = []): AiPersona
    {
        $system = AiSystem::create([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);

        return AiPersona::create(array_merge([
            'ai_system_id' => $system->id,
            'name' => 'Test Bot',
            'slug' => 'test-bot',
            'prompt_template' => 'You are {{persona_name}}.',
            'is_active' => true,
        ], $attributes));
    }

    private function service(): AiPersonaConversationService
    {
        return $this->app->make(AiPersonaConversationService::class);
    }

    // -------------------------------------------------------------- encoding

    public function test_a_finished_turn_is_terminated_with_the_done_sentinel(): void
    {
        $encoded = iterator_to_array((new SseFrameEncoder())->encode([
            ['type' => 'message_start'],
            ['type' => 'message_stop'],
        ]));

        $this->assertSame([
            'data: {"type":"message_start"}' . "\n\n",
            'data: {"type":"message_stop"}' . "\n\n",
            "data: [DONE]\n\n",
        ], $encoded);
    }

    /**
     * An error frame is terminal on its own. Consumers use the absence of the
     * sentinel to tell a failed turn from a finished one.
     */
    public function test_a_failed_turn_is_not_terminated_with_the_done_sentinel(): void
    {
        $encoded = iterator_to_array((new SseFrameEncoder())->encode([
            ['type' => 'message_start'],
            ['type' => 'error', 'message' => 'boom', 'reason' => 'provider_error'],
        ]));

        $this->assertCount(2, $encoded);
        $this->assertStringNotContainsString('[DONE]', implode('', $encoded));
    }

    public function test_an_empty_turn_still_terminates(): void
    {
        $this->assertSame(
            ["data: [DONE]\n\n"],
            iterator_to_array((new SseFrameEncoder())->encode([])),
        );
    }

    // ------------------------------------------------------------ access rules

    public function test_an_inactive_bot_cannot_open_a_conversation(): void
    {
        $persona = $this->makePersona(['is_active' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not active/');

        $this->service()->startConversation($persona);
    }

    public function test_a_bot_requiring_identity_refuses_an_anonymous_visitor(): void
    {
        $persona = $this->makePersona(['require_visitor_identity' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires a visitor name and email/');

        $this->service()->startConversation($persona);
    }

    public function test_a_bot_requiring_identity_accepts_a_named_visitor(): void
    {
        $persona = $this->makePersona(['require_visitor_identity' => true]);

        $conversation = $this->service()->startConversation(
            $persona,
            visitorName: 'Ada',
            visitorEmail: 'ada@example.test',
        );

        $this->assertTrue($conversation->exists);
        $this->assertSame('Ada', $conversation->visitor_name);
    }

    public function test_a_bot_not_requiring_identity_accepts_an_anonymous_visitor(): void
    {
        $conversation = $this->service()->startConversation($this->makePersona());

        $this->assertTrue($conversation->exists);
        $this->assertNull($conversation->visitor_name);
    }

    // ------------------------------------------------------------- cancellation

    public function test_a_supplied_cancellation_check_is_consulted(): void
    {
        $consulted = false;

        $service = $this->service()->usingCancellationCheck(function () use (&$consulted): bool {
            $consulted = true;

            return true;
        });

        $reflection = new \ReflectionMethod($service, 'clientAborted');
        $reflection->setAccessible(true);

        $this->assertTrue($reflection->invoke($service));
        $this->assertTrue($consulted);
    }

    /**
     * connection_aborted() reports 0 outside a live request, so the default is
     * "not cancelled" — which is why a host driving a turn from a queue or the
     * console has to supply its own signal.
     */
    public function test_the_default_cancellation_check_does_not_fire_outside_a_request(): void
    {
        $reflection = new \ReflectionMethod($this->service(), 'clientAborted');
        $reflection->setAccessible(true);

        $this->assertFalse($reflection->invoke($this->service()));
    }

    // -------------------------------------------------------------- presenter

    public function test_the_transcript_excludes_the_system_prompt(): void
    {
        $persona = $this->makePersona();
        $conversation = $this->service()->startConversation($persona);

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hello',
        ]);

        $transcript = $this->app->make(ChatBotPresenter::class)->transcript($conversation);

        $this->assertCount(1, $transcript);
        $this->assertSame('user', $transcript[0]['role']);
        $this->assertArrayHasKey('reasoning_content', $transcript[0]);
        $this->assertArrayHasKey('blocks', $transcript[0]);
    }

    public function test_the_transcript_of_no_conversation_is_empty(): void
    {
        $this->assertSame([], $this->app->make(ChatBotPresenter::class)->transcript(null));
    }

    public function test_a_bots_total_cost_sums_its_conversations(): void
    {
        $persona = $this->makePersona();

        foreach (['0.50', '0.25'] as $cost) {
            AiConversation::create([
                'ai_system_id' => $persona->ai_system_id,
                'ai_persona_id' => $persona->id,
                'feature' => 'persona:test-bot',
                'usage_cost_usd' => $cost,
            ]);
        }

        $this->assertSame(0.75, $this->app->make(ChatBotPresenter::class)->totalCostUsd($persona));
    }

    public function test_an_anonymous_visitor_has_no_listed_conversations(): void
    {
        $persona = $this->makePersona();

        $this->assertSame(
            [],
            $this->app->make(ChatBotPresenter::class)->conversationsFor(null, collect([$persona])),
        );
    }

    // ------------------------------------------------------------- chat hash

    public function test_continuing_a_conversation_ensures_a_chat_hash(): void
    {
        $persona = $this->makePersona();
        $conversation = $this->service()->startConversation($persona);

        $conversation->forceFill(['chat_hash' => null])->save();

        // Draining is not needed: the hash is assigned before the first yield.
        $this->service()->continueConversation($conversation->fresh(), 'Hello')->current();

        $this->assertNotNull($conversation->fresh()->chat_hash);
    }
}

<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiPersona;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiPersonaConversationService;
use Jvjvjv\CodeTalker\Tests\TestCase;

class ThinkingVisibilityTurnTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('ai_conversations', 'uuid')) {
            Schema::table('ai_conversations', function ($table): void {
                $table->string('uuid')->nullable();
            });
        }
    }

    /**
     * An Anthropic stream that carries a thinking block whether or not one
     * was requested.
     */
    private function fakeAnthropicStreamWithThinking(): void
    {
        $events = [
            ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-opus-4-8', 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Pondering']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Hi there']],
            ['type' => 'content_block_stop', 'index' => 1],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 4]],
            ['type' => 'message_stop'],
        ];

        $sse = implode('', array_map(
            static fn (array $event): string => "event: {$event['type']}\ndata: " . json_encode($event) . "\n\n",
            $events,
        ));

        Http::fake(['*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: ?AiConversationMessage}
     */
    private function runTurn(?string $thinkingLevel): array
    {
        Queue::fake();
        $this->fakeAnthropicStreamWithThinking();

        $system = AiSystem::create([
            'name' => 'Claude',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-opus-4-8',
            'max_tokens' => 4096,
            'thinking_level' => $thinkingLevel,
            'is_active' => true,
        ]);

        $persona = AiPersona::create([
            'ai_system_id' => $system->id,
            'name' => 'Bot',
            'slug' => 'bot',
            'prompt_template' => 'You are {{persona_name}}.',
            'is_active' => true,
        ]);

        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        $events = iterator_to_array($service->continueConversation($conversation, 'Hello'), false);

        $reply = AiConversationMessage::query()
            ->where('ai_conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        return [$events, $reply];
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function reasoningEvents(array $events): array
    {
        return array_values(array_filter($events, static fn (array $e): bool => ($e['type'] ?? null) === 'reasoning_block_delta'));
    }

    public function test_reasoning_is_shown_and_stored_above_off(): void
    {
        [$events, $reply] = $this->runTurn('low');

        $this->assertNotSame([], $this->reasoningEvents($events));
        $this->assertSame('Pondering', $reply?->reasoning_content);
        $this->assertSame('Hi there', $reply?->content);
    }

    public function test_reasoning_streamed_to_an_off_system_is_neither_shown_nor_stored(): void
    {
        [$events, $reply] = $this->runTurn('off');

        $this->assertSame([], $this->reasoningEvents($events));
        $this->assertNull($reply?->reasoning_content);
        $this->assertSame('Hi there', $reply?->content);
    }

    public function test_reasoning_is_hidden_when_no_level_is_set(): void
    {
        [$events, $reply] = $this->runTurn(null);

        $this->assertSame([], $this->reasoningEvents($events));
        $this->assertNull($reply?->reasoning_content);
    }
}

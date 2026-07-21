<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Tests\TestCase;

class ReadProviderExchangeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // AiConversation::booted() assigns a uuid, but no package migration
        // creates the column (host apps add it themselves).
        if (! Schema::hasColumn('ai_conversations', 'uuid')) {
            Schema::table('ai_conversations', function ($table): void {
                $table->string('uuid')->nullable();
            });
        }
    }

    /**
     * @return array{system: AiSystem, bot: AiChatBot, conversation: AiConversation, request: AiLlmMessage, exchange: AiProviderExchange, orphan: AiProviderExchange}
     */
    private function seedExchange(): array
    {
        $system = AiSystem::create([
            'name' => 'Test System',
            'provider' => 'lm-studio',
            'api_key' => 'none',
            'model' => 'qwen/qwen3-8b',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);

        $bot = AiChatBot::create([
            'ai_system_id' => $system->id,
            'name' => 'Test Bot',
            'slug' => 'test-bot',
            'prompt_template' => 'You are {{bot_name}}.',
            'is_active' => true,
        ]);

        $conversation = AiConversation::create([
            'ai_system_id' => $system->id,
            'ai_chat_bot_id' => $bot->id,
            'feature' => 'chat-bot:test-bot',
            'title' => 'My Conversation',
            'status' => 'active',
        ]);

        $request = AiLlmMessage::create([
            'ai_conversation_id' => $conversation->id,
            'direction' => 'request',
            'turn_number' => '1',
            'request_data' => ['model' => 'qwen'],
        ]);

        AiLlmMessage::create([
            'ai_conversation_id' => $conversation->id,
            'direction' => 'response',
            'turn_number' => '1',
            'request_data' => ['model' => 'qwen'],
            'response_data' => [
                'events' => [
                    ['type' => 'text_delta', 'delta' => 'Hello '],
                    ['type' => 'text_delta', 'delta' => 'there'],
                    ['type' => 'reasoning_delta', 'delta' => 'hmm'],
                ],
                'stop_reason' => 'stop',
            ],
        ]);

        $exchange = AiProviderExchange::create([
            'provider' => 'lm-studio',
            'endpoint' => '/v1/chat/completions',
            'method' => 'POST',
            'streaming' => true,
            'http_status' => 200,
            'request_body' => json_encode([
                'model' => 'qwen',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are helpful'],
                    ['role' => 'user', 'content' => 'Hi'],
                ],
            ]),
            'raw_response' => "data: {\"choices\":[{\"delta\":{\"content\":\"Streamed \"}}]}\n\n"
                . "data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\n"
                . "data: [DONE]\n\n",
            'model' => 'qwen/qwen3-8b',
            'ai_system_id' => $system->id,
            'ai_conversation_id' => $conversation->id,
            'ai_llm_message_id' => $request->id,
        ]);

        $orphan = AiProviderExchange::create([
            'provider' => 'lm-studio',
            'endpoint' => '/v1/chat/completions',
            'method' => 'POST',
            'streaming' => true,
            'http_status' => 200,
            'raw_response' => "data: {\"choices\":[{\"delta\":{\"content\":\"Orphan chunk\"}}]}\n\n"
                . "data: [DONE]\n\n",
            'model' => 'qwen/qwen3-8b',
            'ai_system_id' => null,
            'ai_conversation_id' => null,
            'ai_llm_message_id' => null,
        ]);

        return compact('system', 'bot', 'conversation', 'request', 'exchange', 'orphan');
    }

    public function test_it_renders_the_exchange_for_a_given_message_id(): void
    {
        $data = $this->seedExchange();

        $this->artisan('ai:read-exchange', ['ai_llm_message_id' => $data['request']->id])
            ->expectsOutputToContain('Test System')
            ->expectsOutputToContain('qwen/qwen3-8b')
            ->expectsOutputToContain('Test Bot')
            ->expectsOutputToContain('My Conversation')
            ->expectsOutputToContain('User message')   // the new label
            ->expectsOutputToContain('Hi')             // the latest user message only
            ->doesntExpectOutputToContain('You are helpful') // system prompt/context dropped
            ->expectsOutputToContain('Hello there')   // sibling response text
            ->expectsOutputToContain('Streamed hi')    // raw_response parsed text
            ->expectsOutputToContain('Orphan chunk')   // trailing orphan row
            ->assertExitCode(0);
    }

    public function test_it_reports_when_no_exchange_exists(): void
    {
        $this->artisan('ai:read-exchange', ['ai_llm_message_id' => 999])
            ->assertExitCode(1);
    }

    public function test_it_drills_down_to_a_message_when_no_id_is_given(): void
    {
        $data = $this->seedExchange();

        $bot = $data['bot'];
        $conversation = AiConversation::find($data['conversation']->id);
        $request = AiLlmMessage::find($data['request']->id);
        $response = AiLlmMessage::where('ai_conversation_id', $conversation->id)
            ->where('direction', 'response')
            ->first();

        $botLabel = $bot->name . ' (id ' . $bot->id . ')';
        $convLabel = $conversation->title . ' (id ' . $conversation->id . ' · ' . $conversation->created_at . ')';
        $msgLabel = '#' . $request->turn_number . ' ' . $request->direction
            . ' (id ' . $request->id . ' · ' . $request->created_at . ')';
        $responseMsgLabel = '#' . $response->turn_number . ' ' . $response->direction
            . ' (id ' . $response->id . ' · ' . $response->created_at . ')';

        $this->artisan('ai:read-exchange')
            ->expectsChoice('Select a chat bot', $botLabel, [
                $botLabel,
                '[unassigned conversations]',
            ])
            ->expectsChoice('Select a conversation', $convLabel, [$convLabel])
            ->expectsChoice('Select a message', $msgLabel, [$msgLabel, $responseMsgLabel])
            ->expectsOutputToContain('Streamed hi')
            ->assertExitCode(0);
    }

    public function test_it_prints_captured_content_containing_console_markup_literally(): void
    {
        $data = $this->seedExchange();

        $data['exchange']->update([
            'request_body' => json_encode([
                'model' => 'qwen',
                'messages' => [
                    ['role' => 'user', 'content' => 'before <comment>keepme</comment> after'],
                ],
            ]),
            'raw_response' => "data: {\"choices\":[{\"delta\":{\"content\":\"bad tag <bg=notacolor>here\"}}]}\n\n"
                . "data: [DONE]\n\n",
        ]);

        $this->artisan('ai:read-exchange', ['ai_llm_message_id' => $data['request']->id])
            ->expectsOutputToContain('<comment>keepme</comment>')
            ->expectsOutputToContain('<bg=notacolor>here')
            ->assertExitCode(0);
    }
}

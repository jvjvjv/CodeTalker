<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Models\AiPersona;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiPersonaConversationService;
use Jvjvjv\CodeTalker\Tests\TestCase;

class RawExchangeChatIntegrationTest extends TestCase
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

    public function test_streaming_chat_turn_records_a_provider_exchange(): void
    {
        Queue::fake();

        $sse = "data: {\"id\":\"c\",\"choices\":[{\"delta\":{\"role\":\"assistant\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n"
            . "data: [DONE]\n";

        Http::fake([
            'http://localhost:1234/v1/chat/completions' => Http::response($sse, 200, [
                'Content-Type' => 'text/event-stream',
            ]),
        ]);

        $system = AiSystem::create([
            'name' => 'Local',
            'provider' => 'lm-studio',
            'api_key' => 'lm-studio-test-key',
            'model' => 'qwen/qwen3.5-9b',
            'base_url' => 'http://localhost:1234',
            'max_tokens' => 256,
            'is_active' => true,
        ]);

        $persona = AiPersona::create([
            'ai_system_id' => $system->id,
            'name' => 'Local Bot',
            'slug' => 'local-bot',
            'prompt_template' => 'You are {{persona_name}}.',
            'is_active' => true,
        ]);

        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        foreach ($service->continueConversation($conversation, 'Hello') as $line) {
            // drain the stream
        }

        $exchange = AiProviderExchange::first();
        $this->assertNotNull($exchange);
        $this->assertSame('lm-studio', $exchange->provider);
        $this->assertTrue($exchange->streaming);
        $this->assertSame($sse, $exchange->raw_response);
        $this->assertSame($conversation->id, $exchange->ai_conversation_id);

        $requestMessage = AiLlmMessage::where('direction', 'request')->first();
        $this->assertSame($requestMessage->id, $exchange->ai_llm_message_id);
    }
}

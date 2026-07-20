<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiMemoryService;
use Jvjvjv\CodeTalker\Tests\TestCase;

class RawExchangeMemoryIntegrationTest extends TestCase
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

    public function test_memory_analysis_records_a_provider_exchange_linked_to_its_conversation(): void
    {
        $completion = json_encode([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => '{"add":[],"update":[],"remove":[]}'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);

        Http::fake(['http://localhost:1234/v1/chat/completions' => Http::response($completion, 200)]);

        $system = AiSystem::create([
            'name' => 'Local',
            'provider' => 'lm-studio',
            'api_key' => 'not-needed-for-lm-studio',
            'model' => 'qwen/qwen3.5-9b',
            'base_url' => 'http://localhost:1234',
            'max_tokens' => 4096,
            'is_active' => true,
        ]);

        $conversation = AiConversation::create([
            'ai_system_id' => $system->id,
            'feature' => 'chat-bot:local',
            'status' => 'active',
        ]);

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hello there',
        ]);

        $result = $this->app->make(AiMemoryService::class)
            ->analyzeConversation($conversation, userId: 1);

        $this->assertSame(['add' => [], 'update' => [], 'remove' => []], $result);

        $exchange = AiProviderExchange::first();
        $this->assertNotNull($exchange);
        $this->assertSame('lm-studio', $exchange->provider);
        $this->assertFalse($exchange->streaming);
        $this->assertSame($completion, $exchange->raw_response);
        $this->assertSame($system->id, $exchange->ai_system_id);
        // Linked to its conversation so memory calls can be correlated with the
        // chat turns they analyze when auditing token spend.
        $this->assertSame($conversation->id, $exchange->ai_conversation_id);
        // Memory extraction creates no AiLlmMessage record, so this stays null.
        $this->assertNull($exchange->ai_llm_message_id);
    }
}

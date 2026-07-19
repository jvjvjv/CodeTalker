<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Tests\TestCase;

class AiProviderExchangeModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_and_casts_an_exchange_row(): void
    {
        $exchange = AiProviderExchange::create([
            'provider' => 'lm-studio',
            'endpoint' => '/v1/chat/completions',
            'method' => 'POST',
            'streaming' => true,
            'http_status' => 200,
            'request_body' => '{"model":"qwen"}',
            'raw_response' => "data: {\"x\":1}\n\ndata: [DONE]",
            'model' => 'qwen/qwen3.5-9b',
            'duration_ms' => 1234,
            'ai_system_id' => null,
            'ai_conversation_id' => null,
            'ai_llm_message_id' => null,
        ]);

        $fresh = $exchange->fresh();

        $this->assertTrue($fresh->streaming);
        $this->assertSame(200, $fresh->http_status);
        $this->assertSame(1234, $fresh->duration_ms);
        $this->assertSame("data: {\"x\":1}\n\ndata: [DONE]", $fresh->raw_response);
        $this->assertNotNull($fresh->created_at);
    }
}

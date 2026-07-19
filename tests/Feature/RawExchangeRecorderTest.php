<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeContext;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeFrame;
use Jvjvjv\CodeTalker\Tests\TestCase;

class RawExchangeRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function pushFrame(string $provider = 'lm-studio', ?string $baseUrl = 'http://localhost:1234/v1'): void
    {
        $this->app->make(RawExchangeContext::class)->push(
            new RawExchangeFrame(
                provider: $provider,
                baseUrl: $baseUrl,
                aiSystemId: 3,
                aiConversationId: 9,
                aiLlmMessageId: 5,
                model: 'qwen/qwen3.5-9b',
            ),
        );
    }

    public function test_it_captures_a_non_streaming_exchange(): void
    {
        $this->pushFrame();
        $body = '{"choices":[{"message":{"content":"hi"}}],"usage":{"prompt_tokens":1}}';
        Http::fake(['http://localhost:1234/v1/chat/completions' => Http::response($body, 200)]);

        $response = Http::post('http://localhost:1234/v1/chat/completions', ['model' => 'qwen']);
        $this->assertSame('hi', $response->json('choices.0.message.content'));

        $exchange = AiProviderExchange::first();
        $this->assertNotNull($exchange);
        $this->assertSame('lm-studio', $exchange->provider);
        $this->assertSame('/v1/chat/completions', $exchange->endpoint);
        $this->assertFalse($exchange->streaming);
        $this->assertSame(200, $exchange->http_status);
        $this->assertSame($body, $exchange->raw_response);
        $this->assertStringContainsString('"model":"qwen"', $exchange->request_body);
        $this->assertSame(9, $exchange->ai_conversation_id);
        $this->assertSame(5, $exchange->ai_llm_message_id);
    }

    public function test_it_captures_a_streaming_exchange_verbatim(): void
    {
        $this->pushFrame();
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"lo\"}}]}\n\n"
            . "data: [DONE]\n\n";
        Http::fake(['http://localhost:1234/v1/chat/completions' => Http::response($sse, 200)]);

        $response = Http::withOptions(['stream' => true])
            ->post('http://localhost:1234/v1/chat/completions', ['model' => 'qwen', 'stream' => true]);

        // Consume the body fully to drive the tee to EOF.
        $consumed = (string) $response->toPsrResponse()->getBody();
        $this->assertSame($sse, $consumed);

        $exchange = AiProviderExchange::first();
        $this->assertNotNull($exchange);
        $this->assertTrue($exchange->streaming);
        $this->assertSame($sse, $exchange->raw_response);
    }

    public function test_it_skips_providers_not_in_the_allow_list(): void
    {
        config()->set('code-talker.raw_exchanges.providers', 'anthropic');
        $this->pushFrame(); // lm-studio frame
        Http::fake(['http://localhost:1234/v1/chat/completions' => Http::response('{}', 200)]);

        Http::post('http://localhost:1234/v1/chat/completions', ['model' => 'qwen']);

        $this->assertSame(0, AiProviderExchange::count());
    }

    public function test_it_skips_when_disabled(): void
    {
        config()->set('code-talker.raw_exchanges.enabled', false);
        $this->pushFrame();
        Http::fake(['http://localhost:1234/v1/chat/completions' => Http::response('{}', 200)]);

        Http::post('http://localhost:1234/v1/chat/completions', ['model' => 'qwen']);

        $this->assertSame(0, AiProviderExchange::count());
    }

    public function test_it_skips_when_no_frame_is_active(): void
    {
        Http::fake(['http://localhost:1234/v1/chat/completions' => Http::response('{}', 200)]);

        Http::post('http://localhost:1234/v1/chat/completions', ['model' => 'qwen']);

        $this->assertSame(0, AiProviderExchange::count());
    }

    public function test_it_skips_when_request_host_does_not_match_the_frame(): void
    {
        $this->pushFrame(baseUrl: 'http://localhost:1234/v1');
        Http::fake(['https://api.anthropic.com/*' => Http::response('{}', 200)]);

        Http::post('https://api.anthropic.com/v1/messages', ['model' => 'x']);

        $this->assertSame(0, AiProviderExchange::count());
    }

    public function test_an_empty_providers_setting_captures_nothing(): void
    {
        config()->set('code-talker.raw_exchanges.providers', '');
        $this->pushFrame(); // lm-studio frame
        Http::fake(['http://localhost:1234/v1/chat/completions' => Http::response('{}', 200)]);

        Http::post('http://localhost:1234/v1/chat/completions', ['model' => 'qwen']);

        $this->assertSame(0, AiProviderExchange::count());
    }

    public function test_all_providers_setting_captures_any_provider(): void
    {
        config()->set('code-talker.raw_exchanges.providers', 'all');
        $this->pushFrame(provider: 'anthropic', baseUrl: 'https://api.anthropic.com/v1');
        Http::fake(['https://api.anthropic.com/*' => Http::response('{}', 200)]);

        Http::post('https://api.anthropic.com/v1/messages', ['model' => 'x']);

        $exchange = AiProviderExchange::first();
        $this->assertNotNull($exchange);
        $this->assertSame('anthropic', $exchange->provider);
    }
}

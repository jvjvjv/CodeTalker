<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Services\LmStudioService;
use Jvjvjv\CodeTalker\Tests\TestCase;

class LmStudioServiceTest extends TestCase
{
    public function test_chat_payload_uses_openai_compat_fields(): void
    {
        Http::fake([
            '*/v1/chat/completions' => Http::response([
                'id' => 'resp-1',
                'model' => 'test-model',
                'choices' => [[
                    'message' => ['content' => 'hello'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ]),
        ]);

        $service = new LmStudioService(
            serverUrl: 'http://localhost:1234',
            model: 'test-model',
            maxTokens: 256,
            contextLength: 8000,
            apiKey: null,
            enableThinking: true,
        );

        $service->message([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(function ($request): bool {
            $this->assertSame('http://localhost:1234/v1/chat/completions', $request->url());

            $body = $request->data();

            // Correct OpenAI-compat field for output length.
            $this->assertArrayHasKey('max_tokens', $body);
            $this->assertSame(256, $body['max_tokens']);

            // Valid LM Studio extension for JIT keepalive.
            $this->assertSame(600, $body['ttl']);

            // Native-only / invalid fields must not be sent to the compat endpoint.
            $this->assertArrayNotHasKey('max_output_tokens', $body);
            $this->assertArrayNotHasKey('context_length', $body);
            $this->assertArrayNotHasKey('comfort', $body);
            $this->assertArrayNotHasKey('enable_thinking', $body);

            return true;
        });
    }

    public function test_tools_are_sent_in_openai_function_format(): void
    {
        Http::fake([
            '*/v1/chat/completions' => Http::response([
                'id' => 'resp-2',
                'model' => 'test-model',
                'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ]),
        ]);

        $service = new LmStudioService(serverUrl: 'http://localhost:1234', model: 'test-model');

        $service
            ->withTools([[
                'name' => 'get_weather',
                'description' => 'Get the weather',
                'input_schema' => ['type' => 'object', 'properties' => []],
            ]])
            ->message([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(function ($request): bool {
            $tool = $request->data()['tools'][0] ?? null;

            $this->assertSame('function', $tool['type'] ?? null);
            $this->assertSame('get_weather', $tool['function']['name'] ?? null);
            $this->assertSame(['type' => 'object', 'properties' => []], $tool['function']['parameters'] ?? null);

            return true;
        });
    }
}

<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Services\ProviderModelsClient;
use Jvjvjv\CodeTalker\Tests\TestCase;

class ProviderModelsClientTest extends TestCase
{
    private ProviderModelsClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new ProviderModelsClient();
    }

    public function test_anthropic_models_use_api_key_and_version_headers(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/models*' => Http::response([
                'data' => [
                    ['id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6', 'created_at' => '2026-01-01T00:00:00Z'],
                ],
            ]),
        ]);

        $models = $this->client->listModels(AiProvider::Anthropic, 'sk-ant-test');

        $this->assertSame('claude-sonnet-4-6', $models[0]['id']);
        $this->assertSame('Claude Sonnet 4.6', $models[0]['display_name']);

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('x-api-key', 'sk-ant-test')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && str_contains($request->url(), 'limit=100');
        });
    }

    public function test_openai_models_use_bearer_token(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response([
                'data' => [['id' => 'gpt-4o'], ['id' => 'gpt-4o-mini']],
            ]),
        ]);

        $models = $this->client->listModels(AiProvider::OpenAI, 'sk-test');

        $this->assertSame(['gpt-4o', 'gpt-4o-mini'], array_column($models, 'id'));
        $this->assertSame('gpt-4o', $models[0]['display_name']);

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer sk-test'));
    }

    public function test_grok_uses_the_xai_models_endpoint(): void
    {
        Http::fake([
            'https://api.x.ai/v1/models' => Http::response(['data' => [['id' => 'grok-3-mini']]]),
        ]);

        $models = $this->client->listModels(AiProvider::Grok, 'xai-test');

        $this->assertSame('grok-3-mini', $models[0]['id']);
    }

    public function test_gemini_models_strip_the_models_prefix(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models*' => Http::response([
                'models' => [
                    ['name' => 'models/gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash'],
                ],
            ]),
        ]);

        $models = $this->client->listModels(AiProvider::Gemini, 'g-key');

        $this->assertSame('gemini-2.5-flash', $models[0]['id']);
        $this->assertSame('Gemini 2.5 Flash', $models[0]['display_name']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'key=g-key'));
    }

    public function test_lm_studio_delegates_to_the_native_api(): void
    {
        Http::fake([
            'http://localhost:1234/api/v1/models' => Http::response([
                'models' => [
                    [
                        'key' => 'qwen3-8b',
                        'type' => 'llm',
                        'display_name' => 'Qwen3 8B',
                        'loaded_instances' => [['id' => 'i1']],
                        'max_context_length' => 32768,
                        'capabilities' => ['vision' => false, 'trained_for_tool_use' => true, 'reasoning' => true],
                    ],
                ],
            ]),
        ]);

        $models = $this->client->listModels(AiProvider::LmStudio, null, 'http://localhost:1234');

        $this->assertSame('qwen3-8b', $models[0]['id']);
        $this->assertTrue($models[0]['loaded']);
        $this->assertSame(32768, $models[0]['max_context_length']);
        $this->assertTrue($models[0]['capabilities']['tools']);
    }

    public function test_custom_base_url_overrides_the_default(): void
    {
        Http::fake([
            'https://proxy.example.com/v1/models' => Http::response(['data' => [['id' => 'custom-model']]]),
        ]);

        $models = $this->client->listModels(AiProvider::OpenAICompatible, null, 'https://proxy.example.com/v1');

        $this->assertSame('custom-model', $models[0]['id']);
    }
}

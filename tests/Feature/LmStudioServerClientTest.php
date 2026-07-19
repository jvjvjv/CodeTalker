<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Services\LmStudioServerClient;
use Jvjvjv\CodeTalker\Tests\TestCase;

class LmStudioServerClientTest extends TestCase
{
    public function test_server_url_normalization_strips_api_and_v1_suffixes(): void
    {
        $this->assertSame('http://localhost:1234', LmStudioServerClient::normalizeServerUrl('http://localhost:1234/'));
        $this->assertSame('http://localhost:1234', LmStudioServerClient::normalizeServerUrl('http://localhost:1234/v1'));
        $this->assertSame('http://localhost:1234', LmStudioServerClient::normalizeServerUrl('http://localhost:1234/api'));
        $this->assertSame('http://localhost:1234', LmStudioServerClient::normalizeServerUrl('http://localhost:1234/api/v1/'));
    }

    public function test_openai_compatible_url_appends_v1(): void
    {
        $client = new LmStudioServerClient('http://192.168.1.5:1234/api/v1');

        $this->assertSame('http://192.168.1.5:1234/v1', $client->openAiCompatibleUrl());
    }

    public function test_is_model_loaded_matches_case_insensitively_on_loaded_instances(): void
    {
        Http::fake([
            'http://localhost:1234/api/v1/models' => Http::response([
                'models' => [
                    ['key' => 'Qwen3-8B', 'type' => 'llm', 'loaded_instances' => [['id' => 'i1']]],
                    ['key' => 'unloaded-model', 'type' => 'llm', 'loaded_instances' => []],
                ],
            ]),
        ]);

        $client = new LmStudioServerClient('http://localhost:1234');

        $this->assertTrue($client->isModelLoaded('qwen3-8b'));
        $this->assertFalse($client->isModelLoaded('unloaded-model'));
    }

    public function test_load_model_posts_the_model_and_context_length(): void
    {
        Http::fake([
            'http://localhost:1234/api/v1/models/load' => Http::response([
                'status' => 'loaded',
                'instance_id' => 'qwen3-8b-1',
                'load_time_seconds' => 2.5,
            ]),
        ]);

        $client = new LmStudioServerClient('http://localhost:1234', 'secret');

        $result = $client->loadModel('qwen3-8b', 8192);

        $this->assertSame('loaded', $result['status']);
        $this->assertSame('qwen3-8b-1', $result['instance_id']);

        Http::assertSent(function ($request): bool {
            return $request['model'] === 'qwen3-8b'
                && $request['context_length'] === 8192
                && $request->hasHeader('Authorization', 'Bearer secret');
        });
    }

    public function test_load_model_omits_context_length_when_not_provided(): void
    {
        Http::fake([
            'http://localhost:1234/api/v1/models/load' => Http::response(['status' => 'loaded']),
        ]);

        (new LmStudioServerClient('http://localhost:1234'))->loadModel('qwen3-8b');

        Http::assertSent(fn ($request): bool => !array_key_exists('context_length', $request->data()));
    }
}

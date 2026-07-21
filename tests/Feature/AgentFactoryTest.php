<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\LaravelAi\AgentFactory;
use Jvjvjv\CodeTalker\Tests\TestCase;

class AgentFactoryTest extends TestCase
{
    private AgentFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->app->make(AgentFactory::class);
    }

    private function makeSystem(array $attributes): AiSystem
    {
        $system = new AiSystem($attributes);
        $system->id = 42;

        return $system;
    }

    public function test_provider_options_config_passes_through_to_the_agent(): void
    {
        $system = $this->makeSystem([
            'provider' => 'lm-studio',
            'base_url' => 'http://localhost:1234',
            'model' => 'qwen/qwen3.6-35b-a3b',
            'config' => [
                'provider_options' => [
                    'frequency_penalty' => 0.3,
                    'repeat_penalty' => 1.15,
                    'top_k' => 40,
                    'seed' => 42,
                    'stop' => ["\n\n\n"],
                ],
            ],
        ]);

        $agent = $this->factory->forSystem($system);

        $this->assertSame([
            'frequency_penalty' => 0.3,
            'repeat_penalty' => 1.15,
            'top_k' => 40,
            'seed' => 42,
            'stop' => ["\n\n\n"],
        ], $agent->providerOptions('lm-studio'));
    }

    public function test_reserved_keys_are_stripped_from_provider_options(): void
    {
        $system = $this->makeSystem([
            'provider' => 'lm-studio',
            'base_url' => 'http://localhost:1234',
            'model' => 'qwen/qwen3.6-35b-a3b',
            'config' => [
                'provider_options' => [
                    'model' => 'a-different-model',
                    'messages' => [['role' => 'user', 'content' => 'hijacked']],
                    'stream' => false,
                    'tools' => [],
                    'tool_choice' => 'none',
                    'response_format' => ['type' => 'json_object'],
                    'seed' => 7,
                ],
            ],
        ]);

        $agent = $this->factory->forSystem($system);

        // response_format is a legitimate lever (LM Studio JSON mode) and is
        // never set structurally by code-talker's own request building, so it
        // is intentionally NOT stripped.
        $this->assertSame([
            'response_format' => ['type' => 'json_object'],
            'seed' => 7,
        ], $agent->providerOptions('lm-studio'));
    }

    public function test_missing_provider_options_is_a_noop(): void
    {
        $system = $this->makeSystem([
            'provider' => 'lm-studio',
            'base_url' => 'http://localhost:1234',
            'model' => 'qwen/qwen3.6-35b-a3b',
        ]);

        $agent = $this->factory->forSystem($system);

        $this->assertSame([], $agent->providerOptions('lm-studio'));
    }

    public function test_provider_options_merge_alongside_anthropic_thinking(): void
    {
        $system = $this->makeSystem([
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 4096,
            'enable_thinking' => true,
            'config' => [
                'provider_options' => [
                    'seed' => 7,
                ],
            ],
        ]);

        $agent = $this->factory->forSystem($system);

        $this->assertSame([
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 1024],
            'seed' => 7,
        ], $agent->providerOptions('anthropic'));
    }
}

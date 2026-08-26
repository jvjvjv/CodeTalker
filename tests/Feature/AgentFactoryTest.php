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

    public function test_agent_show_thinking_mirrors_enable_thinking(): void
    {
        $enabled = $this->makeSystem([
            'provider' => 'lm-studio',
            'base_url' => 'http://localhost:1234',
            'model' => 'qwen/qwen3.6-35b-a3b',
            'enable_thinking' => true,
        ]);

        $disabled = $this->makeSystem([
            'provider' => 'lm-studio',
            'base_url' => 'http://localhost:1234',
            'model' => 'qwen/qwen3.6-35b-a3b',
            'enable_thinking' => false,
        ]);

        $this->assertTrue($this->factory->forSystem($enabled)->showThinking());
        $this->assertFalse($this->factory->forSystem($disabled)->showThinking());
    }

    public function test_lm_studio_reasoning_param_is_sent_only_when_the_model_reports_reasoning_support(): void
    {
        $reasoningModel = $this->makeSystem([
            'provider' => 'lm-studio',
            'base_url' => 'http://localhost:1234',
            'model' => 'qwen/qwen3.6-35b-a3b',
            'enable_thinking' => false,
            'model_capabilities' => ['reasoning' => true],
        ]);

        $this->assertSame(
            ['reasoning' => 'off'],
            $this->factory->forSystem($reasoningModel)->providerOptions('lm-studio'),
        );

        $unknownCapability = $this->makeSystem([
            'provider' => 'lm-studio',
            'base_url' => 'http://localhost:1234',
            'model' => 'some-other-model',
            'enable_thinking' => true,
            'model_capabilities' => ['reasoning' => null],
        ]);

        $this->assertSame([], $this->factory->forSystem($unknownCapability)->providerOptions('lm-studio'));

        $nonReasoningModel = $this->makeSystem([
            'provider' => 'lm-studio',
            'base_url' => 'http://localhost:1234',
            'model' => 'some-other-model',
            'enable_thinking' => true,
            'model_capabilities' => ['reasoning' => false],
        ]);

        $this->assertSame([], $this->factory->forSystem($nonReasoningModel)->providerOptions('lm-studio'));
    }
}

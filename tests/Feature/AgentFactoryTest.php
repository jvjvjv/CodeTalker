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
            'model' => 'claude-opus-4-8',
            'max_tokens' => 4096,
            'thinking_level' => 'high',
            'config' => [
                'provider_options' => [
                    'seed' => 7,
                ],
            ],
        ]);

        $agent = $this->factory->forSystem($system);

        $this->assertSame([
            'thinking' => ['type' => 'adaptive', 'display' => 'summarized'],
            'output_config' => ['effort' => 'high'],
            'seed' => 7,
        ], $agent->providerOptions('anthropic'));
    }

    public function test_provider_options_override_the_thinking_level_key_by_key(): void
    {
        // The merge is shallow: overriding `output_config` replaces the effort
        // the level produced, and leaves `thinking` alone.
        $system = $this->makeSystem([
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-opus-4-8',
            'max_tokens' => 4096,
            'thinking_level' => 'high',
            'config' => [
                'provider_options' => [
                    'output_config' => ['effort' => 'max'],
                ],
            ],
        ]);

        $this->assertSame([
            'thinking' => ['type' => 'adaptive', 'display' => 'summarized'],
            'output_config' => ['effort' => 'max'],
        ], $this->factory->forSystem($system)->providerOptions('anthropic'));
    }

    public function test_an_upgraded_anthropic_system_no_longer_sends_a_fixed_budget(): void
    {
        // Opus 4.7 and later reject `budget_tokens`; the legacy flag now
        // resolves to adaptive thinking at medium effort.
        $system = $this->makeSystem([
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 4096,
            'enable_thinking' => true,
        ]);

        $this->assertSame([
            'thinking' => ['type' => 'adaptive'],
            'output_config' => ['effort' => 'medium'],
        ], $this->factory->forSystem($system)->providerOptions('anthropic'));
    }

    public function test_agent_show_thinking_follows_the_thinking_level(): void
    {
        $system = fn (array $attributes): AiSystem => $this->makeSystem(array_merge([
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-opus-5-5',
            'max_tokens' => 4096,
        ], $attributes));

        $this->assertTrue($this->factory->forSystem($system(['thinking_level' => 'low']))->showThinking());
        $this->assertFalse($this->factory->forSystem($system(['thinking_level' => 'off']))->showThinking());
        $this->assertFalse($this->factory->forSystem($system([]))->showThinking());

        // Opus 5.5 cannot stop thinking: `off` is sent as low effort, and still hidden.
        $clamped = $this->factory->forSystem($system(['thinking_level' => 'off']));
        $this->assertSame(['effort' => 'low'], $clamped->providerOptions('anthropic')['output_config']);
        $this->assertFalse($clamped->showThinking());
    }

    public function test_agent_show_thinking_still_honors_the_legacy_flag(): void
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

    public function test_a_voice_system_cannot_be_turned_into_a_chat_agent(): void
    {
        $system = $this->makeSystem([
            'name' => 'Narrator',
            'provider' => 'elevenlabs',
            'api_key' => 'xi-test',
            'role' => 'audio',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("AI system 'Narrator' has the 'audio' role, but 'chat' is required.");

        $this->factory->forSystem($system);
    }

    public function test_a_system_with_no_role_loaded_still_builds_a_chat_agent(): void
    {
        // forSystem() is handed models built by hand and rows read before the
        // role migration has run; only a role that is known to be wrong is refused.
        $system = $this->makeSystem([
            'provider' => 'lm-studio',
            'base_url' => 'http://localhost:1234',
            'model' => 'qwen/qwen3.6-35b-a3b',
        ]);
        $system->setRawAttributes(array_diff_key($system->getAttributes(), ['role' => true]));

        $this->assertNull($system->role);
        $this->assertSame('qwen/qwen3.6-35b-a3b', $this->factory->forSystem($system)->model());
    }
}

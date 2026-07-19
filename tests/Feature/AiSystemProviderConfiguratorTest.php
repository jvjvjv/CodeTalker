<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Tests\TestCase;

class AiSystemProviderConfiguratorTest extends TestCase
{
    private AiSystemProviderConfigurator $configurator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurator = $this->app->make(AiSystemProviderConfigurator::class);
    }

    private function makeSystem(array $attributes): AiSystem
    {
        $system = new AiSystem($attributes);
        $system->id = 42;

        return $system;
    }

    public function test_base_url_for_returns_the_resolved_lm_studio_url(): void
    {
        $system = $this->makeSystem([
            'provider' => 'lm-studio',
            'model' => 'qwen/qwen3.5-9b',
            'base_url' => 'http://localhost:1234',
        ]);

        $this->assertSame('http://localhost:1234/v1', $this->configurator->baseUrlFor($system));
    }

    public function test_anthropic_system_injects_driver_key_url_and_version(): void
    {
        $name = $this->configurator->providerFor($this->makeSystem([
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
        ]));

        $this->assertSame('code-talker-system-42', $name);
        $this->assertSame([
            'driver' => 'anthropic',
            'key' => 'sk-ant-test',
            'url' => 'https://api.anthropic.com/v1',
            'version' => '2023-06-01',
        ], config('ai.providers.code-talker-system-42'));
    }

    public function test_anthropic_base_url_and_api_version_overrides_win(): void
    {
        $this->configurator->providerFor($this->makeSystem([
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'base_url' => 'https://proxy.example.com/v1',
            'api_version' => '2024-10-22',
        ]));

        $config = config('ai.providers.code-talker-system-42');

        $this->assertSame('https://proxy.example.com/v1', $config['url']);
        $this->assertSame('2024-10-22', $config['version']);
    }

    public function test_grok_maps_to_xai_driver_with_default_base_url(): void
    {
        $this->configurator->providerFor($this->makeSystem([
            'provider' => 'grok',
            'api_key' => 'xai-test',
        ]));

        $this->assertSame([
            'driver' => 'xai',
            'key' => 'xai-test',
            'url' => 'https://api.x.ai/v1',
        ], config('ai.providers.code-talker-system-42'));
    }

    public function test_lm_studio_maps_to_openai_compatible_with_normalized_url_and_no_key(): void
    {
        $this->configurator->providerFor($this->makeSystem([
            'provider' => 'lm-studio',
            'base_url' => 'http://192.168.1.5:1234/api/v1/',
        ]));

        $this->assertSame([
            'driver' => 'openai-compatible',
            'url' => 'http://192.168.1.5:1234/v1',
        ], config('ai.providers.code-talker-system-42'));
    }

    public function test_lm_studio_falls_back_to_configured_server_url(): void
    {
        config()->set('code-talker.providers.lm-studio.server_url', 'http://localhost:9999');

        $this->configurator->providerFor($this->makeSystem([
            'provider' => 'lm-studio',
        ]));

        $this->assertSame('http://localhost:9999/v1', config('ai.providers.code-talker-system-42.url'));
    }

    public function test_openai_and_gemini_map_one_to_one(): void
    {
        $this->configurator->providerFor($this->makeSystem([
            'provider' => 'openai',
            'api_key' => 'sk-test',
        ]));

        $this->assertSame('openai', config('ai.providers.code-talker-system-42.driver'));

        $this->configurator->providerFor($this->makeSystem([
            'provider' => 'gemini',
            'api_key' => 'g-test',
        ]));

        $this->assertSame('gemini', config('ai.providers.code-talker-system-42.driver'));
    }

    public function test_reconfiguring_a_system_replaces_stale_credentials(): void
    {
        $system = $this->makeSystem([
            'provider' => 'openai',
            'api_key' => 'sk-old',
        ]);

        $this->configurator->providerFor($system);
        $this->assertSame('sk-old', config('ai.providers.code-talker-system-42.key'));

        $system->api_key = 'sk-new';
        $this->configurator->providerFor($system);
        $this->assertSame('sk-new', config('ai.providers.code-talker-system-42.key'));
    }

    public function test_unknown_provider_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $system = new AiSystem();
        $system->id = 42;
        $system->provider = 'not-a-provider';

        $this->configurator->providerFor($system);
    }
}

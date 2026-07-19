<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi;

use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\LmStudioServerClient;
use Laravel\Ai\Ai;

/**
 * Bridges AiSystem database records to laravel/ai's config-driven providers.
 *
 * laravel/ai resolves provider credentials from config("ai.providers.{name}")
 * at call time, so each AiSystem gets a dynamically injected entry named
 * "code-talker-system-{id}". The entry is rewritten (and the cached provider
 * instance forgotten) on every resolution so credential edits always take
 * effect, including on long-lived workers.
 */
class AiSystemProviderConfigurator
{
    /**
     * Inject the provider config for an AiSystem and return the provider name.
     */
    public function providerFor(AiSystem $system): string
    {
        $provider = AiProvider::tryFrom($system->provider);

        if ($provider === null) {
            throw new \RuntimeException("Unsupported AI provider: {$system->provider}");
        }

        $name = "code-talker-system-{$system->id}";

        config()->set("ai.providers.{$name}", $this->buildProviderConfig($provider, $system));

        Ai::forgetInstance($name);

        return $name;
    }

    /**
     * The resolved provider base URL for a system (host used for exchange-capture host matching).
     */
    public function baseUrlFor(AiSystem $system): ?string
    {
        $provider = AiProvider::tryFrom($system->provider);

        if ($provider === null) {
            return null;
        }

        return $this->resolveUrl($provider, $system);
    }

    /**
     * @return array<string, string>
     */
    protected function buildProviderConfig(AiProvider $provider, AiSystem $system): array
    {
        $config = ['driver' => $provider->toLaravelAiDriver()];

        if (filled($system->api_key)) {
            $config['key'] = $system->api_key;
        }

        $url = $this->resolveUrl($provider, $system);

        if (filled($url)) {
            $config['url'] = $url;
        }

        if ($provider === AiProvider::Anthropic) {
            $config['version'] = $system->api_version
                ?: config('code-talker.providers.anthropic.api_version', '2023-06-01');
        }

        return $config;
    }

    protected function resolveUrl(AiProvider $provider, AiSystem $system): ?string
    {
        if ($provider === AiProvider::LmStudio) {
            $serverUrl = $system->base_url
                ?: config('code-talker.providers.lm-studio.server_url', 'http://localhost:1234');

            return LmStudioServerClient::normalizeServerUrl($serverUrl) . '/v1';
        }

        return $system->base_url ?: config("code-talker.providers.{$provider->value}.base_url");
    }
}

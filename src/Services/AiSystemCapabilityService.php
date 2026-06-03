<?php

namespace Jvjvjv\CodeTalker\Services;

use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Models\AiSystem;

class AiSystemCapabilityService
{
    /**
     * @return array<int, string>
     */
    public function supportedProviders(): array
    {
        return [AiProvider::LmStudio->value];
    }

    public function supportsProvider(?string $provider): bool
    {
        return in_array($provider, $this->supportedProviders(), true);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function normalizeForPersistence(array &$data): void
    {
        $provider = $data['provider'] ?? null;

        if (!is_string($provider)) {
            return;
        }

        if (in_array($provider, [AiProvider::LmStudio->value, AiProvider::OpenAICompatible->value], true)
            && (!array_key_exists('api_key', $data) || $data['api_key'] === null)) {
            $data['api_key'] = '';
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function hydrateForPersistence(array &$data): void
    {
        $provider = $data['provider'] ?? null;
        $model = $data['model'] ?? null;

        if (!is_string($provider) || !is_string($model) || blank($model) || !$this->supportsProvider($provider)) {
            return;
        }

        $capabilities = $this->resolveCapabilities(
            provider: $provider,
            model: $model,
            baseUrl: is_string($data['base_url'] ?? null) && filled($data['base_url']) ? $data['base_url'] : null,
            apiKey: is_string($data['api_key'] ?? null) && filled($data['api_key']) ? $data['api_key'] : null,
        );

        if ($capabilities !== null) {
            $data['model_capabilities'] = $capabilities;
        }
    }

    /**
     * @return array{reasoning: bool, vision: bool, tools: bool, max_context_length: int|null}|null
     */
    public function backfillSystem(AiSystem $system): ?array
    {
        return $this->resolveCapabilities(
            provider: $system->provider,
            model: $system->model,
            baseUrl: $system->base_url,
            apiKey: $system->api_key,
        );
    }

    /**
     * @return array{reasoning: bool, vision: bool, tools: bool, max_context_length: int|null}|null
     */
    public function resolveCapabilities(
        string $provider,
        string $model,
        ?string $baseUrl = null,
        ?string $apiKey = null,
    ): ?array {
        return match ($provider) {
            AiProvider::LmStudio->value => $this->resolveLmStudioCapabilities($model, $baseUrl),
            default => null,
        };
    }

    /**
     * @return array{reasoning: bool, vision: bool, tools: bool, max_context_length: int|null}|null
     */
    private function resolveLmStudioCapabilities(string $model, ?string $baseUrl = null): ?array
    {
        $service = new LmStudioService(serverUrl: $baseUrl);

        $matchingModel = collect($service->listModels())
            ->first(static fn (array $listedModel): bool => ($listedModel['id'] ?? null) === $model);

        if (!is_array($matchingModel)) {
            return null;
        }

        return [
            'reasoning' => (bool) data_get($matchingModel, 'capabilities.reasoning', false),
            'vision' => (bool) data_get($matchingModel, 'capabilities.vision', false),
            'tools' => (bool) data_get($matchingModel, 'capabilities.tools', false),
            'max_context_length' => isset($matchingModel['max_context_length'])
                ? (int) $matchingModel['max_context_length']
                : null,
        ];
    }
}

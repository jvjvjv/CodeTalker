<?php

namespace Jvjvjv\CodeTalker\Services;

use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Models\AiSystem;

/**
 * Lists the models available from a provider's API.
 *
 * laravel/ai has no model-listing API, so this client makes the raw HTTP
 * calls itself (one endpoint per provider) for the admin "fetch models"
 * feature and model-availability checks.
 */
class ProviderModelsClient
{
    /**
     * @return array<int, array{id: string, display_name: string}>
     */
    public function listModelsForSystem(AiSystem $system): array
    {
        $provider = AiProvider::tryFrom($system->provider);

        if ($provider === null) {
            throw new \RuntimeException("Unsupported AI provider: {$system->provider}");
        }

        return $this->listModels($provider, $system->api_key, $system->base_url);
    }

    /**
     * @return array<int, array{id: string, display_name: string}>
     */
    public function listModels(AiProvider $provider, ?string $apiKey = null, ?string $baseUrl = null): array
    {
        return match ($provider) {
            AiProvider::Anthropic => $this->anthropicModels($apiKey, $baseUrl),
            AiProvider::OpenAI, AiProvider::OpenAICompatible, AiProvider::Grok => $this->openAiCompatibleModels($provider, $apiKey, $baseUrl),
            AiProvider::Gemini => $this->geminiModels($apiKey, $baseUrl),
            AiProvider::LmStudio => (new LmStudioServerClient($baseUrl, $apiKey))->listModels(),
        };
    }

    /**
     * @return array<int, array{id: string, display_name: string, created_at: string|null}>
     */
    private function anthropicModels(?string $apiKey, ?string $baseUrl): array
    {
        $response = Http::withHeaders([
            'x-api-key' => (string) $apiKey,
            'anthropic-version' => (string) config('code-talker.providers.anthropic.api_version', '2023-06-01'),
        ])
            ->timeout(15)
            ->get($this->resolveBaseUrl(AiProvider::Anthropic, $baseUrl) . '/models', ['limit' => 100]);

        $response->throw();

        return collect($response->json('data', []))
            ->filter(static fn (mixed $model): bool => is_array($model) && isset($model['id']))
            ->map(static fn (array $model): array => [
                'id' => (string) $model['id'],
                'display_name' => (string) ($model['display_name'] ?? $model['id']),
                'created_at' => $model['created_at'] ?? null,
            ])
            ->values()
            ->toArray();
    }

    /**
     * @return array<int, array{id: string, display_name: string}>
     */
    private function openAiCompatibleModels(AiProvider $provider, ?string $apiKey, ?string $baseUrl): array
    {
        $request = Http::timeout(15);

        if (filled($apiKey)) {
            $request = $request->withToken($apiKey);
        }

        $response = $request->get($this->resolveBaseUrl($provider, $baseUrl) . '/models');

        $response->throw();

        return collect($response->json('data', []))
            ->filter(static fn (mixed $model): bool => is_array($model) && isset($model['id']))
            ->map(static fn (array $model): array => [
                'id' => (string) $model['id'],
                'display_name' => (string) $model['id'],
            ])
            ->values()
            ->toArray();
    }

    /**
     * @return array<int, array{id: string, display_name: string}>
     */
    private function geminiModels(?string $apiKey, ?string $baseUrl): array
    {
        $response = Http::timeout(15)
            ->get($this->resolveBaseUrl(AiProvider::Gemini, $baseUrl) . '/models', ['key' => (string) $apiKey]);

        $response->throw();

        return collect($response->json('models', []))
            ->filter(static fn (mixed $model): bool => is_array($model) && isset($model['name']))
            ->map(static function (array $model): array {
                $name = (string) $model['name'];
                $modelId = str_starts_with($name, 'models/') ? substr($name, 7) : $name;

                return [
                    'id' => $modelId,
                    'display_name' => (string) ($model['displayName'] ?? $modelId),
                ];
            })
            ->values()
            ->toArray();
    }

    private function resolveBaseUrl(AiProvider $provider, ?string $baseUrl): string
    {
        $url = $baseUrl ?: config("code-talker.providers.{$provider->value}.base_url");

        if (blank($url)) {
            throw new \RuntimeException("No base URL configured for provider: {$provider->value}");
        }

        return rtrim((string) $url, '/');
    }
}

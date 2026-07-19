<?php

namespace Jvjvjv\CodeTalker\Services;

use Illuminate\Support\Facades\Http;

/**
 * Client for LM Studio's native management API (/api/v1/*).
 *
 * Chat completions go through laravel/ai's openai-compatible driver; this
 * client covers what that driver cannot: listing models on disk, checking
 * whether a model is loaded, and explicitly loading a model into memory.
 */
class LmStudioServerClient
{
    private string $serverUrl;

    private ?string $apiKey;

    public function __construct(?string $serverUrl = null, ?string $apiKey = null)
    {
        $this->serverUrl = self::normalizeServerUrl(
            $serverUrl ?? config('code-talker.providers.lm-studio.server_url', 'http://localhost:1234')
        );

        $this->apiKey = $apiKey;
    }

    /**
     * The OpenAI-compatible chat endpoint base URL for this server.
     */
    public function openAiCompatibleUrl(): string
    {
        return $this->serverUrl . '/v1';
    }

    /**
     * Lists all models available on disk (loaded and unloaded).
     * Uses the native LM Studio /api/v1/models endpoint.
     *
     * @return array<int, array{id: string, display_name: string, loaded: bool, max_context_length: int|null, capabilities: array{vision: bool, tools: bool, reasoning: bool}}>
     */
    public function listModels(): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(15)
            ->get($this->serverUrl . '/api/v1/models');

        $response->throw();

        $models = $response->json('models', []);

        if (!is_array($models)) {
            return [];
        }

        return collect($models)
            ->filter(static fn (mixed $m): bool => is_array($m) && isset($m['key']) && ($m['type'] ?? '') === 'llm')
            ->map(static function (array $m): array {
                $capabilities = is_array($m['capabilities'] ?? null) ? $m['capabilities'] : [];
                $reasoning = $capabilities['reasoning'] ?? null;

                return [
                    'id' => (string) $m['key'],
                    'display_name' => (string) ($m['display_name'] ?? $m['key']),
                    'loaded' => !empty($m['loaded_instances']),
                    'max_context_length' => isset($m['max_context_length']) ? (int) $m['max_context_length'] : null,
                    'capabilities' => [
                        'vision' => (bool) ($capabilities['vision'] ?? false),
                        'tools' => (bool) ($capabilities['trained_for_tool_use'] ?? false),
                        'reasoning' => is_array($reasoning) || (bool) $reasoning,
                    ],
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Returns true if the model currently has at least one active loaded instance.
     */
    public function isModelLoaded(string $model): bool
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(15)
            ->get($this->serverUrl . '/api/v1/models');

        if ($response->failed()) {
            return false;
        }

        $models = $response->json('models', []);

        if (!is_array($models)) {
            return false;
        }

        return collect($models)
            ->contains(static function (mixed $m) use ($model): bool {
                if (!is_array($m)) {
                    return false;
                }

                return strcasecmp((string) ($m['key'] ?? ''), $model) === 0
                    && !empty($m['loaded_instances']);
            });
    }

    /**
     * Explicitly loads the model into memory via the LM Studio native API.
     *
     * @return array{status: string, instance_id: string, load_time_seconds: float}
     */
    public function loadModel(string $model, ?int $contextLength = null): array
    {
        $payload = [
            'model' => $model,
        ];

        if ($contextLength !== null) {
            $payload['context_length'] = $contextLength;
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(300)
            ->post($this->serverUrl . '/api/v1/models/load', $payload);

        $response->throw();

        $data = $response->json();

        return [
            'status' => (string) ($data['status'] ?? 'loaded'),
            'instance_id' => (string) ($data['instance_id'] ?? $model),
            'load_time_seconds' => (float) ($data['load_time_seconds'] ?? 0.0),
        ];
    }

    /**
     * Strip any /v1, /api, or /api/v1 suffix so the base server URL can be
     * combined with either the native or OpenAI-compatible path prefix.
     */
    public static function normalizeServerUrl(string $serverUrl): string
    {
        $normalized = rtrim($serverUrl, '/');

        if (str_ends_with($normalized, '/api/v1')) {
            $normalized = substr($normalized, 0, -7);
        } elseif (str_ends_with($normalized, '/api')) {
            $normalized = substr($normalized, 0, -4);
        } elseif (str_ends_with($normalized, '/v1')) {
            $normalized = substr($normalized, 0, -3);
        }

        return rtrim($normalized, '/');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = ['content-type' => 'application/json'];

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        return $headers;
    }
}

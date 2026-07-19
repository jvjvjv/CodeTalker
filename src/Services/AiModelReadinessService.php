<?php

namespace Jvjvjv\CodeTalker\Services;

use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\LaravelAi\AgentFactory;
use Throwable;

class AiModelReadinessService
{
    public function __construct(
        private AgentFactory $agentFactory,
        private ProviderModelsClient $modelsClient,
    ) {
    }

    /**
     * @return array{state: string, provider: string, model: string, message: string, checked_at: string}
     */
    public function statusForSystem(AiSystem $system): array
    {
        $provider = AiProvider::tryFrom($system->provider);

        if ($provider === null) {
            return $this->statusPayload(
                state: 'unavailable',
                system: $system,
                message: 'Unsupported provider configuration.',
            );
        }

        try {
            if ($provider === AiProvider::LmStudio) {
                $isLoaded = $this->lmStudioClient($system)->isModelLoaded(trim((string) $system->model));

                return $this->statusPayload(
                    state: $isLoaded ? 'loaded' : 'not_loaded',
                    system: $system,
                    message: $isLoaded ? 'Model is loaded.' : 'Model is not loaded yet.',
                );
            }

            $models = $this->modelsClient->listModelsForSystem($system);
            $configuredModel = trim((string) $system->model);

            $hasModel = collect($models)->contains(
                static fn (array $model): bool => strcasecmp((string) ($model['id'] ?? ''), $configuredModel) === 0,
            );

            if ($hasModel) {
                return $this->statusPayload(
                    state: 'loaded',
                    system: $system,
                    message: 'Model is available.',
                );
            }

            if ($provider === AiProvider::OpenAICompatible) {
                return $this->statusPayload(
                    state: 'not_loaded',
                    system: $system,
                    message: 'Model is not loaded yet.',
                );
            }

            return $this->statusPayload(
                state: 'not_loaded',
                system: $system,
                message: 'Model is not available from this provider.',
            );
        } catch (Throwable $exception) {
            return $this->statusPayload(
                state: 'unavailable',
                system: $system,
                message: 'Provider is unavailable: ' . $exception->getMessage(),
            );
        }
    }

    /**
     * @return array{state: string, provider: string, model: string, message: string, checked_at: string, warmup_attempted: bool}
     */
    public function warmUpSystem(AiSystem $system): array
    {
        $initialStatus = $this->statusForSystem($system);

        if ($initialStatus['state'] === 'loaded') {
            return $initialStatus + ['warmup_attempted' => false];
        }

        return $this->attemptWarmUp(
            $system,
            $system->context_length,
            fn (): array => $this->statusForSystem($system),
            $initialStatus,
        );
    }

    /**
     * @return array{state: string, provider: string, model: string, message: string, checked_at: string}
     */
    public function statusForChatBot(AiChatBot $bot): array
    {
        $bot->loadMissing('aiSystem');

        return $this->statusForSystem($bot->aiSystem);
    }

    /**
     * @return array{state: string, provider: string, model: string, message: string, checked_at: string, warmup_attempted: bool}
     */
    public function warmUpChatBot(AiChatBot $bot): array
    {
        $bot->loadMissing('aiSystem');

        $initialStatus = $this->statusForChatBot($bot);

        if ($initialStatus['state'] === 'loaded') {
            return $initialStatus + ['warmup_attempted' => false];
        }

        return $this->attemptWarmUp(
            $bot->aiSystem,
            $bot->resolvedContextLength(),
            fn (): array => $this->statusForChatBot($bot),
            $initialStatus,
        );
    }

    /**
     * LM Studio models are loaded explicitly via the native API; other
     * OpenAI-compatible endpoints are warmed with a minimal completion.
     *
     * @param callable(): array $statusCheck
     * @param array{state: string, provider: string, model: string, message: string, checked_at: string} $initialStatus
     * @return array{state: string, provider: string, model: string, message: string, checked_at: string, warmup_attempted: bool}
     */
    private function attemptWarmUp(AiSystem $system, ?int $contextLength, callable $statusCheck, array $initialStatus): array
    {
        $provider = AiProvider::tryFrom($system->provider);

        if ($provider === AiProvider::LmStudio) {
            try {
                $this->lmStudioClient($system)->loadModel(trim((string) $system->model), $contextLength);

                // LM Studio may take a short moment to reflect the loaded instance.
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $status = $statusCheck();
                    if ($status['state'] === 'loaded') {
                        return $status + ['warmup_attempted' => true];
                    }

                    usleep(200000);
                }

                return $statusCheck() + ['warmup_attempted' => true];
            } catch (Throwable $exception) {
                return $this->statusPayload(
                    state: 'unavailable',
                    system: $system,
                    message: 'Model load failed: ' . $exception->getMessage(),
                ) + ['warmup_attempted' => true];
            }
        }

        if ($provider !== AiProvider::OpenAICompatible) {
            return $initialStatus + ['warmup_attempted' => false];
        }

        try {
            $this->agentFactory
                ->forSystem($system, maxTokens: 16)
                ->prompt('Reply with OK.');

            return $statusCheck() + ['warmup_attempted' => true];
        } catch (Throwable $exception) {
            return $this->statusPayload(
                state: 'unavailable',
                system: $system,
                message: 'Warmup failed: ' . $exception->getMessage(),
            ) + ['warmup_attempted' => true];
        }
    }

    private function lmStudioClient(AiSystem $system): LmStudioServerClient
    {
        return new LmStudioServerClient($system->base_url, $system->api_key);
    }

    /**
     * @return array{state: string, provider: string, model: string, message: string, checked_at: string}
     */
    private function statusPayload(string $state, AiSystem $system, string $message): array
    {
        return [
            'state' => $state,
            'provider' => (string) $system->provider,
            'model' => (string) $system->model,
            'message' => $message,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}

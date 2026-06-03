<?php

namespace Jvjvjv\CodeTalker\Services;

use Jvjvjv\CodeTalker\Contracts\CanLoadModels;
use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Throwable;

class AiModelReadinessService
{
    public function __construct(
        private AiClientFactory $clientFactory,
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
            $client = $this->clientFactory->forSystem($system);

            if ($client instanceof CanLoadModels) {
                $isLoaded = $client->isModelLoaded(trim((string) $system->model));

                return $this->statusPayload(
                    state: $isLoaded ? 'loaded' : 'not_loaded',
                    system: $system,
                    message: $isLoaded ? 'Model is loaded.' : 'Model is not loaded yet.',
                );
            }

            $models = $client->listModels();
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
        $provider = AiProvider::tryFrom($system->provider);

        if ($initialStatus['state'] === 'loaded') {
            return $initialStatus + ['warmup_attempted' => false];
        }

        $client = $this->clientFactory->forSystem($system);

        if ($client instanceof CanLoadModels) {
            try {
                $client->loadModel(trim((string) $system->model), $system->context_length);

                // LM Studio may take a short moment to reflect the loaded instance.
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $status = $this->statusForSystem($system);
                    if ($status['state'] === 'loaded') {
                        return $status + ['warmup_attempted' => true];
                    }

                    usleep(200000);
                }

                return $this->statusForSystem($system) + ['warmup_attempted' => true];
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
            $this->clientFactory->forSystem($system)
                ->withMaxTokens(16)
                ->message([
                    ['role' => 'user', 'content' => 'Reply with OK.'],
                ]);

            return $this->statusForSystem($system) + ['warmup_attempted' => true];
        } catch (Throwable $exception) {
            return $this->statusPayload(
                state: 'unavailable',
                system: $system,
                message: 'Warmup failed: ' . $exception->getMessage(),
            ) + ['warmup_attempted' => true];
        }
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
        $provider = AiProvider::tryFrom($bot->aiSystem->provider);

        if ($initialStatus['state'] === 'loaded') {
            return $initialStatus + ['warmup_attempted' => false];
        }

        $client = $this->clientFactory->forSystem($bot->aiSystem);

        if ($client instanceof CanLoadModels) {
            try {
                $client->loadModel(
                    trim((string) $bot->aiSystem->model),
                    $bot->resolvedContextLength(),
                );

                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $status = $this->statusForChatBot($bot);
                    if ($status['state'] === 'loaded') {
                        return $status + ['warmup_attempted' => true];
                    }

                    usleep(200000);
                }

                return $this->statusForChatBot($bot) + ['warmup_attempted' => true];
            } catch (Throwable $exception) {
                return $this->statusPayload(
                    state: 'unavailable',
                    system: $bot->aiSystem,
                    message: 'Model load failed: ' . $exception->getMessage(),
                ) + ['warmup_attempted' => true];
            }
        }

        if ($provider !== AiProvider::OpenAICompatible) {
            return $initialStatus + ['warmup_attempted' => false];
        }

        try {
            $this->clientFactory->forSystem($bot->aiSystem)
                ->withMaxTokens(16)
                ->message([
                    ['role' => 'user', 'content' => 'Reply with OK.'],
                ]);

            return $this->statusForChatBot($bot) + ['warmup_attempted' => true];
        } catch (Throwable $exception) {
            return $this->statusPayload(
                state: 'unavailable',
                system: $bot->aiSystem,
                message: 'Warmup failed: ' . $exception->getMessage(),
            ) + ['warmup_attempted' => true];
        }
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


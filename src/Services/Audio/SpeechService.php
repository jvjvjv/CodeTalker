<?php

namespace Jvjvjv\CodeTalker\Services\Audio;

use Jvjvjv\CodeTalker\Enums\AiSystemRole;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiSystemResolver;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeFrame;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeRecorder;
use Laravel\Ai\Audio;
use Laravel\Ai\Responses\AudioResponse;

/**
 * Text-to-speech through an audio-role AiSystem.
 *
 * Calling laravel/ai's Audio facade directly would resolve credentials from
 * the host's own `ai.providers.*` config; going through the configurator keeps
 * them on the AiSystem record, as the chat path does.
 */
class SpeechService
{
    public function __construct(
        private AiSystemProviderConfigurator $configurator,
        private RawExchangeRecorder $rawExchanges,
        private AiSystemResolver $resolver,
    ) {
    }

    public function forSystem(
        AiSystem $system,
        string $text,
        ?string $voice = null,
        ?string $instructions = null,
    ): AudioResponse {
        $this->resolver->ensureUsable($system, AiSystemRole::Audio);

        $pending = Audio::of($text);

        if ($voice !== null) {
            $pending->voice($voice);
        }

        if ($instructions !== null) {
            $pending->instructions($instructions);
        }

        $providerName = $this->configurator->providerFor($system);

        return $this->rawExchanges->capture(
            RawExchangeFrame::forSystem($system, $this->configurator),
            // A blank model is passed as null so the provider's default applies.
            fn () => $pending->generate($providerName, $system->model ?: null),
        );
    }

    public function forFeature(
        string $feature,
        string $text,
        ?string $voice = null,
        ?string $instructions = null,
    ): AudioResponse {
        return $this->forSystem(
            $this->resolver->forFeature($feature, AiSystemRole::Audio),
            $text,
            $voice,
            $instructions,
        );
    }
}

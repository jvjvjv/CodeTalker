<?php

namespace Jvjvjv\CodeTalker\Services\Audio;

use Illuminate\Http\UploadedFile;
use Jvjvjv\CodeTalker\Enums\AiSystemRole;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiSystemResolver;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeFrame;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeRecorder;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;

/**
 * Speech-to-text through a transcription-role AiSystem. See SpeechService for
 * why this does not call laravel/ai's facade directly.
 */
class TranscriptionService
{
    public function __construct(
        private AiSystemProviderConfigurator $configurator,
        private RawExchangeRecorder $rawExchanges,
        private AiSystemResolver $resolver,
    ) {
    }

    public function forSystem(
        AiSystem $system,
        TranscribableAudio|UploadedFile $audio,
        ?string $language = null,
        bool $diarize = false,
    ): TranscriptionResponse {
        $this->resolver->ensureUsable($system, AiSystemRole::Transcription);

        $pending = Transcription::of($audio)->diarize($diarize);

        if ($language !== null) {
            $pending->language($language);
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
        TranscribableAudio|UploadedFile $audio,
        ?string $language = null,
        bool $diarize = false,
    ): TranscriptionResponse {
        return $this->forSystem(
            $this->resolver->forFeature($feature, AiSystemRole::Transcription),
            $audio,
            $language,
            $diarize,
        );
    }
}

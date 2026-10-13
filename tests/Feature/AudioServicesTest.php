<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Models\AiSystemFeatureDefault;
use Jvjvjv\CodeTalker\Services\Audio\SpeechService;
use Jvjvjv\CodeTalker\Services\Audio\TranscriptionService;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Laravel\Ai\Audio;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;
use RuntimeException;

class AudioServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    private function system(string $role, array $attributes = []): AiSystem
    {
        return AiSystem::create(array_merge([
            'name' => 'Voice',
            'provider' => 'openai',
            'api_key' => 'sk-voice',
            'model' => 'custom-voice-model',
            'role' => $role,
            'is_active' => true,
        ], $attributes));
    }

    private function speech(): SpeechService
    {
        return $this->app->make(SpeechService::class);
    }

    private function transcription(): TranscriptionService
    {
        return $this->app->make(TranscriptionService::class);
    }

    private function clip(): Base64Audio
    {
        return new Base64Audio(base64_encode('not-really-audio'), 'audio/mpeg');
    }

    public function test_speech_is_generated_through_the_systems_own_provider(): void
    {
        Audio::fake();
        $system = $this->system('audio');

        $response = $this->speech()->forSystem($system, 'Hello there', voice: 'nova', instructions: 'Warmly.');

        $this->assertInstanceOf(AudioResponse::class, $response);
        Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'Hello there'
            && $prompt->voice === 'nova'
            && $prompt->instructions === 'Warmly.'
            && $prompt->model === 'custom-voice-model'
            && $prompt->provider->name() === "code-talker-system-{$system->id}"
            && $prompt->provider->providerCredentials()['key'] === 'sk-voice');
    }

    public function test_speech_for_a_blank_model_uses_the_provider_default(): void
    {
        Audio::fake();
        $system = $this->system('audio', ['model' => '']);

        $this->speech()->forSystem($system, 'Hello');

        Audio::assertGenerated(
            fn (AudioPrompt $prompt): bool => $prompt->model === $prompt->provider->defaultAudioModel()
        );
    }

    public function test_speech_refuses_a_system_with_another_role(): void
    {
        Audio::fake();
        $system = $this->system('chat');

        try {
            $this->speech()->forSystem($system, 'Hello');
            $this->fail('A chat system generated speech.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("has the 'chat' role, but 'audio' is required", $e->getMessage());
        }

        Audio::assertNothingGenerated();
    }

    public function test_speech_refuses_an_inactive_system(): void
    {
        Audio::fake();
        $system = $this->system('audio', ['is_active' => false]);

        try {
            $this->speech()->forSystem($system, 'Hello');
            $this->fail('An inactive system generated speech.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('is inactive', $e->getMessage());
        }

        Audio::assertNothingGenerated();
    }

    public function test_speech_resolves_a_feature_to_its_default_system(): void
    {
        Audio::fake();
        $system = $this->system('audio');
        AiSystemFeatureDefault::create(['ai_system_id' => $system->id, 'feature' => 'voice:greeting']);

        $this->speech()->forFeature('voice:greeting', 'Welcome', voice: 'nova');

        Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'Welcome'
            && $prompt->voice === 'nova'
            && $prompt->provider->name() === "code-talker-system-{$system->id}");
    }

    public function test_speech_refuses_a_feature_mapped_to_a_chat_system(): void
    {
        Audio::fake();
        $system = $this->system('chat');
        AiSystemFeatureDefault::create(['ai_system_id' => $system->id, 'feature' => 'voice:greeting']);

        try {
            $this->speech()->forFeature('voice:greeting', 'Welcome');
            $this->fail('A chat feature default generated speech.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("has the 'chat' role, but 'audio' is required", $e->getMessage());
        }

        Audio::assertNothingGenerated();
    }

    public function test_audio_is_transcribed_through_the_systems_own_provider(): void
    {
        Transcription::fake(['hello world']);
        $system = $this->system('transcription');

        $response = $this->transcription()->forSystem($system, $this->clip(), language: 'en');

        $this->assertInstanceOf(TranscriptionResponse::class, $response);
        $this->assertSame('hello world', $response->text);
        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => $prompt->language === 'en'
            && $prompt->diarize === false
            && $prompt->model === 'custom-voice-model'
            && $prompt->provider->name() === "code-talker-system-{$system->id}"
            && $prompt->provider->providerCredentials()['key'] === 'sk-voice');
    }

    public function test_diarization_is_forwarded(): void
    {
        Transcription::fake();
        $system = $this->system('transcription');

        $this->transcription()->forSystem($system, $this->clip(), diarize: true);

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => $prompt->isDiarized());
    }

    public function test_transcription_for_a_blank_model_uses_the_provider_default(): void
    {
        Transcription::fake();
        $system = $this->system('transcription', ['model' => '']);

        $this->transcription()->forSystem($system, $this->clip());

        Transcription::assertGenerated(
            fn (TranscriptionPrompt $prompt): bool => $prompt->model === $prompt->provider->defaultTranscriptionModel()
        );
    }

    public function test_transcription_refuses_a_system_with_another_role(): void
    {
        Transcription::fake();
        $system = $this->system('audio');

        try {
            $this->transcription()->forSystem($system, $this->clip());
            $this->fail('An audio system transcribed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(
                "has the 'audio' role, but 'transcription' is required",
                $e->getMessage(),
            );
        }

        Transcription::assertNothingGenerated();
    }

    public function test_transcription_refuses_an_inactive_system(): void
    {
        Transcription::fake();
        $system = $this->system('transcription', ['is_active' => false]);

        try {
            $this->transcription()->forSystem($system, $this->clip());
            $this->fail('An inactive system transcribed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('is inactive', $e->getMessage());
        }

        Transcription::assertNothingGenerated();
    }

    public function test_transcription_resolves_a_feature_to_its_default_system(): void
    {
        Transcription::fake();
        $system = $this->system('transcription');
        AiSystemFeatureDefault::create(['ai_system_id' => $system->id, 'feature' => 'voice:dictation']);

        $this->transcription()->forFeature('voice:dictation', $this->clip(), diarize: true);

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => $prompt->isDiarized()
            && $prompt->provider->name() === "code-talker-system-{$system->id}");
    }

    public function test_a_speech_call_is_captured_once_its_provider_is_on_the_allow_list(): void
    {
        config()->set('code-talker.raw_exchanges.providers', 'elevenlabs');
        Http::fake(['https://api.elevenlabs.io/*' => Http::response('mp3-bytes', 200)]);
        $system = $this->system('audio', ['provider' => 'elevenlabs', 'api_key' => 'xi-key', 'model' => '']);

        $response = $this->speech()->forSystem($system, 'Hello', voice: 'voice-id');

        $this->assertSame('mp3-bytes', $response->content());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/voice-id'
            && $request->hasHeader('xi-api-key', 'xi-key'));

        $exchange = AiProviderExchange::sole();
        $this->assertSame('elevenlabs', $exchange->provider);
        $this->assertSame($system->id, $exchange->ai_system_id);
        $this->assertNull($exchange->ai_conversation_id);
    }

    public function test_a_speech_call_is_not_captured_under_the_default_allow_list(): void
    {
        Http::fake(['https://api.elevenlabs.io/*' => Http::response('mp3-bytes', 200)]);
        $system = $this->system('audio', ['provider' => 'elevenlabs', 'api_key' => 'xi-key', 'model' => '']);

        $this->speech()->forSystem($system, 'Hello', voice: 'voice-id');

        $this->assertSame(0, AiProviderExchange::count());
    }
}

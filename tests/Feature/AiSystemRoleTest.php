<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Enums\AiSystemRole;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\Management\AiPersonaManager;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use PHPUnit\Framework\Attributes\DataProvider;

class AiSystemRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    public function test_the_role_values_are_listed(): void
    {
        $this->assertSame(['chat', 'audio', 'transcription'], AiSystemRole::values());
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function providerRoleMatrix(): array
    {
        $table = [
            'anthropic' => [true, false, false],
            'openai' => [true, true, true],
            'gemini' => [true, true, true],
            'grok' => [true, false, false],
            'elevenlabs' => [false, true, true],
            'openai-compatible' => [true, false, false],
            'lm-studio' => [true, false, false],
        ];

        $cases = [];

        foreach ($table as $provider => [$chat, $audio, $transcription]) {
            $cases["{$provider} chat"] = [$provider, 'chat', $chat];
            $cases["{$provider} audio"] = [$provider, 'audio', $audio];
            $cases["{$provider} transcription"] = [$provider, 'transcription', $transcription];
        }

        return $cases;
    }

    #[DataProvider('providerRoleMatrix')]
    public function test_a_provider_reports_the_roles_it_can_serve(string $provider, string $role, bool $expected): void
    {
        $this->assertSame($expected, AiProvider::from($provider)->supportsRole(AiSystemRole::from($role)));
    }

    public function test_the_matrix_covers_every_provider(): void
    {
        $covered = array_unique(array_map(static fn (array $case): string => $case[0], self::providerRoleMatrix()));

        $this->assertEqualsCanonicalizing(AiProvider::values(), $covered);
    }

    /**
     * The table above is only a claim about laravel/ai. This reads the claim
     * off the provider classes themselves, so a dependency upgrade that adds
     * or drops a gateway fails here instead of at a host's first voice call.
     */
    public function test_supported_roles_match_what_the_laravel_ai_driver_implements(): void
    {
        $contracts = [
            'chat' => TextProvider::class,
            'audio' => AudioProvider::class,
            'transcription' => TranscriptionProvider::class,
        ];

        foreach (AiProvider::cases() as $provider) {
            $name = "role-probe-{$provider->value}";

            config()->set("ai.providers.{$name}", [
                'driver' => $provider->toLaravelAiDriver(),
                'key' => 'probe',
                'url' => 'http://localhost',
            ]);

            $instance = Ai::instance($name);

            foreach ($contracts as $role => $contract) {
                $this->assertSame(
                    $instance instanceof $contract,
                    $provider->supportsRole(AiSystemRole::from($role)),
                    "{$provider->value} / {$role}",
                );
            }
        }
    }

    public function test_elevenlabs_maps_to_the_eleven_driver_and_requires_a_key(): void
    {
        $this->assertSame('eleven', AiProvider::ElevenLabs->toLaravelAiDriver());
        $this->assertTrue(AiProvider::ElevenLabs->requiresApiKey());
    }

    public function test_the_configurator_injects_the_eleven_driver_with_the_records_own_credentials(): void
    {
        $system = new AiSystem(['provider' => 'elevenlabs', 'api_key' => 'xi-test', 'role' => 'audio']);
        $system->id = 77;

        $name = $this->app->make(AiSystemProviderConfigurator::class)->providerFor($system);

        $this->assertSame([
            'driver' => 'eleven',
            'key' => 'xi-test',
            'url' => 'https://api.elevenlabs.io/v1',
        ], config("ai.providers.{$name}"));
    }

    public function test_a_record_created_without_a_role_is_a_chat_system(): void
    {
        // Written around the model, as every row that predates the column was.
        $id = DB::table('ai_systems')->insertGetId([
            'name' => 'Legacy',
            'provider' => 'anthropic',
            'api_key' => '',
            'model' => 'claude-sonnet-4-6',
        ]);

        $this->assertSame(AiSystemRole::Chat, AiSystem::findOrFail($id)->role);
    }

    public function test_an_unsaved_system_defaults_to_the_chat_role(): void
    {
        $this->assertSame(AiSystemRole::Chat, (new AiSystem(['provider' => 'anthropic']))->role);
    }

    public function test_systems_can_be_filtered_by_role(): void
    {
        AiSystem::create(['name' => 'Chat', 'provider' => 'openai', 'api_key' => 'k', 'model' => 'gpt-4o']);
        AiSystem::create(['name' => 'Voice', 'provider' => 'elevenlabs', 'api_key' => 'k', 'model' => '', 'role' => 'audio']);

        $this->assertSame(['Voice'], AiSystem::role(AiSystemRole::Audio)->pluck('name')->all());
        $this->assertSame(['Chat'], AiSystem::role(AiSystemRole::Chat)->pluck('name')->all());
    }

    public function test_voice_systems_are_not_offered_to_a_persona(): void
    {
        AiSystem::create(['name' => 'Chat', 'provider' => 'openai', 'api_key' => 'k', 'model' => 'gpt-4o']);
        AiSystem::create(['name' => 'Voice', 'provider' => 'elevenlabs', 'api_key' => 'k', 'model' => '', 'role' => 'audio']);

        $offered = $this->app->make(AiPersonaManager::class)->availableSystems();

        $this->assertSame(['Chat'], array_column($offered, 'name'));
    }
}

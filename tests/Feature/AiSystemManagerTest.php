<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Enums\AiSystemRole;
use Jvjvjv\CodeTalker\Enums\ThinkingLevel;
use Jvjvjv\CodeTalker\Models\AiOperator;
use Jvjvjv\CodeTalker\Models\AiPersona;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Models\AiSystemFeatureDefault;
use Jvjvjv\CodeTalker\Models\AiSystemPrompt;
use Jvjvjv\CodeTalker\Services\Management\AiSystemManager;
use Jvjvjv\CodeTalker\Services\Web\WebToolPolicy;
use Jvjvjv\CodeTalker\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AiSystemManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    private function manager(): AiSystemManager
    {
        return $this->app->make(AiSystemManager::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function validAttributes(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
        ], $overrides);
    }

    public function test_it_creates_a_system(): void
    {
        $system = $this->manager()->create($this->validAttributes());

        $this->assertTrue($system->exists);
        $this->assertSame('Test System', $system->name);
        $this->assertSame('anthropic', $system->provider);
    }

    public function test_json_string_fields_are_decoded_before_persistence(): void
    {
        $system = $this->manager()->create($this->validAttributes([
            'config' => json_encode(['provider_options' => ['top_k' => 40]]),
            'pricing_profile' => json_encode(['default' => ['input_per_million' => 3.0]]),
        ]));

        // The model casts these to arrays; storing the raw string would round-trip
        // as a string-of-a-string rather than an array.
        $this->assertIsArray($system->fresh()->config);
        $this->assertSame(40, $system->fresh()->config['provider_options']['top_k']);
        $this->assertIsArray($system->fresh()->pricing_profile);
    }

    public function test_a_well_formed_web_tool_policy_is_accepted_and_decoded(): void
    {
        $system = $this->manager()->create($this->validAttributes([
            'web_tool_policy' => json_encode([
                'allowed_domains' => ['api.example.com'],
                'credentials' => ['api.example.com' => ['Authorization' => 'Bearer secret']],
            ]),
        ]));

        $this->assertIsArray($system->fresh()->web_tool_policy);
        $this->assertSame(['api.example.com'], $system->fresh()->web_tool_policy['allowed_domains']);
    }

    public function test_a_web_tool_policy_with_non_string_allowed_domains_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->manager()->create($this->validAttributes([
            'web_tool_policy' => json_encode(['allowed_domains' => [123]]),
        ]));
    }

    public function test_a_web_tool_policy_with_a_non_object_credentials_map_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->manager()->create($this->validAttributes([
            'web_tool_policy' => json_encode(['credentials' => 'not-an-object']),
        ]));
    }

    public function test_a_web_tool_policy_with_a_non_string_header_value_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->manager()->create($this->validAttributes([
            'web_tool_policy' => json_encode(['credentials' => ['api.example.com' => ['Authorization' => ['nested']]]]),
        ]));
    }

    public function test_no_web_tool_policy_leaves_the_system_unrestricted(): void
    {
        $system = $this->manager()->create($this->validAttributes());

        $this->assertNull($system->fresh()->web_tool_policy);
    }

    public function test_custom_prompt_text_creates_a_prompt_record_and_links_it(): void
    {
        $system = $this->manager()->create($this->validAttributes([
            'custom_system_prompt' => 'You are a careful assistant.',
        ]));

        $this->assertNotNull($system->system_prompt_id);

        $prompt = AiSystemPrompt::find($system->system_prompt_id);
        $this->assertSame('You are a careful assistant.', $prompt->content);
        $this->assertSame('Custom prompt', $prompt->description);
        $this->assertSame('Test System Custom Prompt', $prompt->title);
    }

    public function test_a_generated_prompt_title_is_truncated_to_the_column_limit(): void
    {
        $system = $this->manager()->create($this->validAttributes([
            'name' => str_repeat('a', 100),
            'custom_system_prompt' => 'Prompt body.',
        ]));

        $prompt = AiSystemPrompt::find($system->system_prompt_id);

        $this->assertSame(64, mb_strlen($prompt->title));
    }

    public function test_an_explicit_prompt_id_wins_over_custom_text(): void
    {
        $existing = AiSystemPrompt::create([
            'title' => 'Existing',
            'description' => 'Existing prompt',
            'content' => 'Existing content.',
        ]);

        // A package migration seeds default prompts, so compare against the
        // count at this point rather than assuming an empty table.
        $before = AiSystemPrompt::count();

        $system = $this->manager()->create($this->validAttributes([
            'system_prompt_id' => $existing->id,
            'custom_system_prompt' => 'Ignored.',
        ]));

        $this->assertSame($existing->id, $system->system_prompt_id);
        $this->assertSame($before, AiSystemPrompt::count());
    }

    public function test_provider_and_model_are_immutable_on_update(): void
    {
        $system = $this->manager()->create($this->validAttributes());

        $this->manager()->update($system, [
            'name' => 'Renamed',
            'max_tokens' => 2048,
            'provider' => 'openai',
            'model' => 'gpt-4o',
        ]);

        $system->refresh();

        $this->assertSame('Renamed', $system->name);
        $this->assertSame('anthropic', $system->provider);
        $this->assertSame('claude-sonnet-4-6', $system->model);
    }

    public function test_a_blank_base_url_on_update_keeps_the_stored_one(): void
    {
        $system = $this->manager()->create($this->validAttributes([
            'base_url' => 'https://example.test/v1',
        ]));

        $this->manager()->update($system, [
            'name' => 'Test System',
            'max_tokens' => 1024,
            'base_url' => null,
        ]);

        $this->assertSame('https://example.test/v1', $system->fresh()->base_url);
    }

    public function test_deleting_a_system_deactivates_its_chat_bots_and_reports_the_count(): void
    {
        $system = $this->manager()->create($this->validAttributes());

        foreach (['one', 'two'] as $slug) {
            AiPersona::create([
                'ai_system_id' => $system->id,
                'name' => "Bot {$slug}",
                'slug' => $slug,
                'prompt_template' => 'You are {{persona_name}}.',
                'is_active' => true,
            ]);
        }

        $deactivated = $this->manager()->delete($system);

        $this->assertSame(2, $deactivated);
        $this->assertSame(0, AiPersona::where('is_active', true)->count());
        // Deactivated, not deleted — the relationship and its history survive.
        $this->assertSame(2, AiPersona::count());
        $this->assertSoftDeleted($system);
    }

    public function test_deleting_a_system_with_no_bots_reports_zero(): void
    {
        $system = $this->manager()->create($this->validAttributes());

        $this->assertSame(0, $this->manager()->delete($system));
    }

    public function test_duplicating_a_system_marks_the_copy_and_leaves_feature_defaults_alone(): void
    {
        $system = $this->manager()->create($this->validAttributes([
            'feature_defaults' => ['persona:support'],
        ]));

        $clone = $this->manager()->duplicate($system);

        $this->assertSame('Test System (copy)', $clone->name);
        $this->assertNotSame($system->id, $clone->id);
        $this->assertNotNull($clone->duplicated_at);
        $this->assertNull($system->duplicated_at);

        // A feature has one default system; copying the claims would silently
        // steal them from the original.
        $this->assertSame(
            $system->id,
            AiSystemFeatureDefault::where('feature', 'persona:support')->value('ai_system_id')
        );
    }

    public function test_duplicating_can_copy_feature_defaults_when_asked(): void
    {
        $system = $this->manager()->create($this->validAttributes([
            'feature_defaults' => ['persona:support'],
        ]));

        $clone = $this->manager()->duplicate($system, copyFeatureDefaults: true);

        $this->assertSame(
            $clone->id,
            AiSystemFeatureDefault::where('feature', 'persona:support')->value('ai_system_id')
        );
    }

    public function test_claiming_a_feature_takes_it_from_the_system_that_held_it(): void
    {
        $first = $this->manager()->create($this->validAttributes([
            'feature_defaults' => ['persona:support'],
        ]));

        $second = $this->manager()->create($this->validAttributes([
            'name' => 'Second System',
            'feature_defaults' => ['persona:support'],
        ]));

        $this->assertSame(1, AiSystemFeatureDefault::where('feature', 'persona:support')->count());
        $this->assertSame(
            $second->id,
            AiSystemFeatureDefault::where('feature', 'persona:support')->value('ai_system_id')
        );
        $this->assertSame(0, AiSystemFeatureDefault::where('ai_system_id', $first->id)->count());
    }

    public function test_syncing_removes_defaults_no_longer_listed(): void
    {
        $system = $this->manager()->create($this->validAttributes([
            'feature_defaults' => ['a', 'b'],
        ]));

        $this->manager()->update($system, [
            'name' => 'Test System',
            'max_tokens' => 1024,
            'feature_defaults' => ['b'],
        ]);

        $this->assertSame(['b'], AiSystemFeatureDefault::pluck('feature')->all());
    }

    public function test_claimed_features_can_exclude_one_systems_own_claims(): void
    {
        $first = $this->manager()->create($this->validAttributes([
            'feature_defaults' => ['a'],
        ]));

        $this->manager()->create($this->validAttributes([
            'name' => 'Second',
            'feature_defaults' => ['b'],
        ]));

        $this->assertEqualsCanonicalizing(['a', 'b'], $this->manager()->claimedFeatures());
        $this->assertSame(['b'], $this->manager()->claimedFeatures(excluding: $first));
    }

    public function test_an_api_key_is_required_for_providers_that_need_one(): void
    {
        $this->expectException(ValidationException::class);

        $this->manager()->create([
            'name' => 'No Key',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
        ]);
    }

    public function test_local_providers_do_not_require_an_api_key(): void
    {
        $system = $this->manager()->create([
            'name' => 'Local',
            'provider' => 'openai-compatible',
            'model' => 'local-model',
            'max_tokens' => 1024,
            'base_url' => 'http://localhost:8080/v1',
        ]);

        $this->assertTrue($system->exists);
        // normalizeForPersistence() substitutes an empty string so the column
        // is never null for these providers.
        $this->assertSame('', $system->api_key);
    }

    public function test_feature_keys_are_unrestricted_by_default(): void
    {
        $system = $this->manager()->create($this->validAttributes([
            'feature_defaults' => ['anything-at-all'],
        ]));

        $this->assertSame(['anything-at-all'], AiSystemFeatureDefault::pluck('feature')->all());
    }

    public function test_configured_feature_keys_are_enforced(): void
    {
        config()->set('code-talker.feature_keys', ['allowed-feature']);

        $this->expectException(ValidationException::class);

        $this->manager()->create($this->validAttributes([
            'feature_defaults' => ['not-allowed'],
        ]));
    }

    public function test_listing_models_refuses_a_provider_that_needs_a_key_without_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->manager()->availableModels(AiProvider::Anthropic, null);
    }

    private function credentialsMigration(): object
    {
        return require __DIR__ . '/../../database/migrations/2026_10_12_000001_change_ai_systems_credentials_to_text.php';
    }

    private function systemWithSecrets(): AiSystem
    {
        return $this->manager()->create($this->validAttributes([
            'credentials' => json_encode(['client_secret' => 'shh']),
            'web_tool_policy' => json_encode([
                'allowed_domains' => ['api.example.com'],
                'credentials' => ['api.example.com' => ['Authorization' => 'Bearer secret']],
            ]),
        ]));
    }

    public function test_a_serialized_system_carries_none_of_its_secrets(): void
    {
        $system = $this->systemWithSecrets()->fresh();

        foreach ([$system->toArray(), json_decode($system->toJson(), true)] as $serialized) {
            $this->assertArrayNotHasKey('api_key', $serialized);
            $this->assertArrayNotHasKey('credentials', $serialized);
            $this->assertArrayNotHasKey('web_tool_policy', $serialized);
            $this->assertSame('Test System', $serialized['name']);
            $this->assertSame('anthropic', $serialized['provider']);
        }
    }

    public function test_hidden_secrets_are_still_readable_as_attributes(): void
    {
        $system = $this->systemWithSecrets()->fresh();

        $this->assertSame('sk-ant-test', $system->api_key);
        $this->assertSame(['client_secret' => 'shh'], $system->credentials);
        $this->assertSame(['api.example.com'], $system->web_tool_policy['allowed_domains']);
    }

    public function test_a_host_can_make_one_hidden_secret_visible(): void
    {
        $serialized = $this->systemWithSecrets()->fresh()->makeVisible('web_tool_policy')->toArray();

        $this->assertSame(['api.example.com'], $serialized['web_tool_policy']['allowed_domains']);
        $this->assertArrayNotHasKey('api_key', $serialized);
        $this->assertArrayNotHasKey('credentials', $serialized);
    }

    public function test_a_persona_serialized_with_its_system_carries_none_of_the_secrets(): void
    {
        $system = $this->systemWithSecrets();

        $persona = AiPersona::create([
            'ai_system_id' => $system->id,
            'name' => 'Support',
            'slug' => 'support',
            'prompt_template' => 'You are {{persona_name}}.',
            'is_active' => true,
        ])->load('aiSystem');

        $nested = $persona->toArray()['ai_system'];

        $this->assertSame($system->id, $nested['id']);
        $this->assertArrayNotHasKey('api_key', $nested);
        $this->assertArrayNotHasKey('credentials', $nested);
        $this->assertArrayNotHasKey('web_tool_policy', $nested);
    }

    public function test_duplicating_a_system_copies_its_secrets(): void
    {
        $system = $this->systemWithSecrets();

        $clone = $this->manager()->duplicate($system)->fresh();

        $this->assertSame('sk-ant-test', $clone->api_key);
        $this->assertSame($system->fresh()->credentials, $clone->credentials);
        $this->assertSame($system->fresh()->web_tool_policy, $clone->web_tool_policy);
    }

    public function test_an_update_that_omits_the_secrets_keeps_them(): void
    {
        $system = $this->systemWithSecrets();

        $this->manager()->update($system, ['name' => 'Renamed', 'max_tokens' => 2048]);

        $fresh = $system->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame(['client_secret' => 'shh'], $fresh->credentials);
        $this->assertSame(['api.example.com'], $fresh->web_tool_policy['allowed_domains']);
    }

    public function test_an_update_that_submits_the_secrets_blank_keeps_them(): void
    {
        // A form cannot prefill a hidden attribute, so it posts the field
        // empty. That must not read as "remove the allow-list".
        foreach ([null, ''] as $blank) {
            $system = $this->systemWithSecrets();

            $this->manager()->update($system, [
                'name' => 'Renamed',
                'max_tokens' => 2048,
                'credentials' => $blank,
                'web_tool_policy' => $blank,
            ]);

            $fresh = $system->fresh();
            $this->assertSame('Renamed', $fresh->name);
            $this->assertSame(['client_secret' => 'shh'], $fresh->credentials);
            $this->assertSame(['api.example.com'], $fresh->web_tool_policy['allowed_domains']);
        }
    }

    public function test_an_update_clears_a_secret_only_when_given_an_empty_object(): void
    {
        $system = $this->systemWithSecrets();

        $this->manager()->update($system, [
            'name' => 'Test System',
            'max_tokens' => 1024,
            'credentials' => '{}',
            'web_tool_policy' => '{}',
        ]);

        $fresh = $system->fresh();
        $this->assertSame([], $fresh->credentials);
        $this->assertSame([], $fresh->web_tool_policy);
        $this->assertNull(WebToolPolicy::fromArray($fresh->web_tool_policy)->allowedDomains);
    }

    public function test_an_update_can_still_replace_a_secret(): void
    {
        $system = $this->systemWithSecrets();

        $this->manager()->update($system, [
            'name' => 'Test System',
            'max_tokens' => 1024,
            'web_tool_policy' => json_encode(['allowed_domains' => ['other.example.com']]),
        ]);

        $this->assertSame(['other.example.com'], $system->fresh()->web_tool_policy['allowed_domains']);
        $this->assertSame(['client_secret' => 'shh'], $system->fresh()->credentials);
    }

    public function test_an_update_that_submits_a_json_null_secret_keeps_it(): void
    {
        // "null" is valid JSON, so it passes validation as a string and only
        // becomes null once decoded.
        $system = $this->systemWithSecrets();

        $this->manager()->update($system, [
            'name' => 'Test System',
            'max_tokens' => 1024,
            'credentials' => 'null',
            'web_tool_policy' => ' null ',
        ]);

        $fresh = $system->fresh();
        $this->assertSame(['client_secret' => 'shh'], $fresh->credentials);
        $this->assertSame(['api.example.com'], $fresh->web_tool_policy['allowed_domains']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function secretsThatAreNotObjects(): array
    {
        $cases = [];

        foreach (['credentials', 'web_tool_policy'] as $field) {
            foreach (['a number' => '5', 'a string' => '"api.example.com"', 'a boolean' => 'true', 'a list' => '["api.example.com"]'] as $label => $json) {
                $cases["{$field} as {$label}"] = [$field, $json];
            }
        }

        return $cases;
    }

    #[DataProvider('secretsThatAreNotObjects')]
    public function test_a_secret_that_does_not_decode_to_an_object_is_rejected(string $field, string $json): void
    {
        try {
            $this->manager()->create($this->validAttributes([$field => $json]));
            $this->fail("{$field} was accepted as {$json}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }

        $system = $this->systemWithSecrets();

        try {
            $this->manager()->update($system, ['name' => 'Test System', 'max_tokens' => 1024, $field => $json]);
            $this->fail("{$field} was updated to {$json}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }

        $this->assertSame(['api.example.com'], $system->fresh()->web_tool_policy['allowed_domains']);
        $this->assertSame(['client_secret' => 'shh'], $system->fresh()->credentials);
    }

    public function test_a_web_tool_policy_with_an_unknown_key_is_rejected(): void
    {
        // A misspelt `allowed_domains` would otherwise be stored and read back
        // as "no allow-list".
        try {
            $this->manager()->create($this->validAttributes([
                'web_tool_policy' => json_encode(['allowed_domain' => ['api.example.com']]),
            ]));
            $this->fail('A policy with an unknown key was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['The web tool policy has an unknown key: allowed_domain.'],
                $e->errors()['web_tool_policy'],
            );
        }
    }

    public function test_the_credentials_migration_declares_a_text_column(): void
    {
        // SQLite reports a `json` column as text, so the type is asserted
        // against the SQL a validating database is sent. Pretend mode compiles
        // it without connecting.
        config()->set('database.connections.pretend_mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'pretend',
            'username' => 'pretend',
            'password' => '',
            'prefix' => '',
        ]);

        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pretend_mysql');

        try {
            $queries = DB::connection()->pretend(fn () => $this->credentialsMigration()->up());
        } finally {
            DB::setDefaultConnection($original);
        }

        $this->assertSame(
            ['alter table `ai_systems` modify `credentials` text null'],
            array_column($queries, 'query'),
        );
    }

    public function test_credentials_are_stored_encrypted_and_read_back(): void
    {
        $system = $this->systemWithSecrets();

        $raw = DB::table('ai_systems')->where('id', $system->id)->value('credentials');

        $this->assertIsString($raw);
        $this->assertNull(json_decode($raw, true));
        $this->assertStringNotContainsString('shh', $raw);
        $this->assertSame(['client_secret' => 'shh'], $system->fresh()->credentials);
    }

    public function test_a_system_without_credentials_stores_null(): void
    {
        $system = $this->manager()->create($this->validAttributes());

        $this->assertNull(DB::table('ai_systems')->where('id', $system->id)->value('credentials'));
        $this->assertNull($system->fresh()->credentials);
    }

    public function test_credentials_stored_before_the_column_change_survive_it(): void
    {
        Schema::table('ai_systems', fn ($table) => $table->json('credentials')->nullable()->change());

        $system = $this->systemWithSecrets();

        $this->credentialsMigration()->up();

        $this->assertSame(['client_secret' => 'shh'], $system->fresh()->credentials);
    }

    public function test_a_system_created_without_a_role_is_a_chat_system(): void
    {
        $system = $this->manager()->create($this->validAttributes());

        $this->assertSame(AiSystemRole::Chat, $system->fresh()->role);
    }

    public function test_an_audio_system_needs_only_a_provider_and_a_key(): void
    {
        $system = $this->manager()->create([
            'name' => 'Narrator',
            'provider' => 'elevenlabs',
            'role' => 'audio',
            'api_key' => 'xi-test',
        ]);

        $fresh = $system->fresh();
        $this->assertSame(AiSystemRole::Audio, $fresh->role);
        $this->assertSame('', $fresh->model);
        $this->assertSame('xi-test', $fresh->api_key);
    }

    public function test_a_chat_system_still_requires_a_model_and_max_tokens(): void
    {
        try {
            $this->manager()->create(['name' => 'Chat', 'provider' => 'openai', 'api_key' => 'sk']);
            $this->fail('A chat system was created without a model.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('model', $e->errors());
            $this->assertArrayHasKey('max_tokens', $e->errors());
        }
    }

    public function test_a_provider_that_cannot_serve_the_role_is_rejected_naming_both(): void
    {
        foreach (['lm-studio', 'openai-compatible'] as $provider) {
            foreach (['audio', 'transcription'] as $role) {
                try {
                    $this->manager()->create([
                        'name' => 'Local voice',
                        'provider' => $provider,
                        'role' => $role,
                    ]);
                    $this->fail("{$provider} was accepted for {$role}.");
                } catch (ValidationException $e) {
                    $this->assertSame(
                        ["The {$provider} provider cannot serve the {$role} role."],
                        $e->errors()['role'],
                    );
                }
            }
        }

        $this->assertSame(0, AiSystem::count());
    }

    public function test_an_audio_only_provider_is_rejected_for_chat_even_when_no_role_is_given(): void
    {
        try {
            $this->manager()->create($this->validAttributes(['provider' => 'elevenlabs']));
            $this->fail('ElevenLabs was accepted as a chat system.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['The elevenlabs provider cannot serve the chat role.'],
                $e->errors()['provider'],
            );
        }
    }

    public function test_an_unknown_role_is_rejected(): void
    {
        try {
            $this->manager()->create($this->validAttributes(['role' => 'video']));
            $this->fail('An unknown role was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role', $e->errors());
        }
    }

    public function test_an_elevenlabs_system_requires_an_api_key(): void
    {
        try {
            $this->manager()->create(['name' => 'Narrator', 'provider' => 'elevenlabs', 'role' => 'audio']);
            $this->fail('An ElevenLabs system was created without a key.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('api_key', $e->errors());
        }
    }

    public function test_every_capable_provider_is_accepted_for_a_voice_role(): void
    {
        foreach (['openai', 'gemini', 'elevenlabs'] as $provider) {
            foreach (['audio', 'transcription'] as $role) {
                $system = $this->manager()->create([
                    'name' => "{$provider} {$role}",
                    'provider' => $provider,
                    'role' => $role,
                    'api_key' => 'key',
                ]);

                $this->assertSame($role, $system->fresh()->role->value);
            }
        }
    }

    public function test_an_update_cannot_move_a_system_to_a_role_its_provider_cannot_serve(): void
    {
        $system = $this->manager()->create($this->validAttributes());

        try {
            $this->manager()->update($system, ['name' => 'Test System', 'max_tokens' => 1024, 'role' => 'audio']);
            $this->fail('An Anthropic system was moved to the audio role.');
        } catch (ValidationException $e) {
            $this->assertSame(['The anthropic provider cannot serve the audio role.'], $e->errors()['role']);
        }

        $this->assertSame(AiSystemRole::Chat, $system->fresh()->role);
    }

    public function test_an_update_that_omits_the_role_keeps_it(): void
    {
        $system = $this->manager()->create([
            'name' => 'Narrator',
            'provider' => 'elevenlabs',
            'role' => 'audio',
            'api_key' => 'xi-test',
        ]);

        // No max_tokens: it means nothing to a voice system, so it is not demanded of one.
        $this->manager()->update($system, ['name' => 'Renamed']);

        $fresh = $system->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame(AiSystemRole::Audio, $fresh->role);
    }

    public function test_providers_are_listed_by_the_role_they_can_serve(): void
    {
        $this->assertSame(['openai', 'gemini', 'elevenlabs'], AiSystemManager::providersFor(AiSystemRole::Audio));
        $this->assertSame(['openai', 'gemini', 'elevenlabs'], AiSystemManager::providersFor(AiSystemRole::Transcription));
        $this->assertSame(
            ['anthropic', 'openai', 'openai-compatible', 'lm-studio', 'gemini', 'grok'],
            AiSystemManager::providersFor(AiSystemRole::Chat),
        );
    }

    public function test_listing_models_for_a_voice_only_provider_returns_none(): void
    {
        Http::preventStrayRequests();

        $this->assertSame([], $this->manager()->availableModels(AiProvider::ElevenLabs, 'xi-test'));
    }

    public function test_a_thinking_level_is_stored_and_mirrored_to_the_legacy_flag(): void
    {
        $system = $this->manager()->create($this->validAttributes(['thinking_level' => 'low']))->fresh();

        $this->assertSame(ThinkingLevel::Low, $system->thinking_level);
        $this->assertTrue($system->enable_thinking);

        $off = $this->manager()->create($this->validAttributes(['thinking_level' => 'off']))->fresh();

        $this->assertSame(ThinkingLevel::Off, $off->thinking_level);
        $this->assertFalse($off->enable_thinking);
    }

    public function test_a_lone_legacy_flag_is_translated_to_a_level(): void
    {
        $on = $this->manager()->create($this->validAttributes(['enable_thinking' => true]))->fresh();
        $off = $this->manager()->create($this->validAttributes(['enable_thinking' => '0']))->fresh();

        $this->assertSame(ThinkingLevel::Medium, $on->thinking_level);
        $this->assertSame(ThinkingLevel::Off, $off->thinking_level);
        $this->assertFalse($off->enable_thinking);
    }

    public function test_a_submitted_level_wins_over_the_legacy_flag(): void
    {
        $system = $this->manager()->create($this->validAttributes([
            'thinking_level' => 'high',
            'enable_thinking' => false,
        ]))->fresh();

        $this->assertSame(ThinkingLevel::High, $system->thinking_level);
        $this->assertTrue($system->enable_thinking);
    }

    public function test_an_update_that_omits_thinking_keeps_the_stored_level(): void
    {
        $system = $this->manager()->create($this->validAttributes(['thinking_level' => 'xhigh']));

        $this->manager()->update($system, ['name' => 'Renamed', 'max_tokens' => 1024]);

        $this->assertSame(ThinkingLevel::XHigh, $system->fresh()->thinking_level);
        $this->assertTrue($system->fresh()->enable_thinking);
    }

    public function test_an_update_can_clear_the_level_back_to_the_provider_default(): void
    {
        $system = $this->manager()->create($this->validAttributes(['thinking_level' => 'high']));

        $this->manager()->update($system, ['name' => 'Same', 'max_tokens' => 1024, 'thinking_level' => null]);

        $this->assertNull($system->fresh()->thinking_level);
        $this->assertNull($system->fresh()->enable_thinking);
        $this->assertNull($system->fresh()->thinkingLevel());
    }

    public function test_an_unknown_thinking_level_is_rejected(): void
    {
        try {
            $this->manager()->create($this->validAttributes(['thinking_level' => 'extreme']));
            $this->fail('An unknown thinking level was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('thinking_level', $e->errors());
        }

        $this->assertSame(0, AiSystem::count());
    }

    public function test_thinking_levels_for_an_effort_model(): void
    {
        $this->assertSame(
            ['levels' => ['off', 'low', 'medium', 'high', 'xhigh', 'max'], 'controls_effort' => true],
            $this->manager()->thinkingLevels(AiProvider::Anthropic, 'claude-opus-4-8'),
        );
    }

    public function test_thinking_levels_still_offer_off_for_a_model_that_cannot_stop_reasoning(): void
    {
        $levels = $this->manager()->thinkingLevels('anthropic', 'claude-opus-5-5');

        $this->assertSame('off', $levels['levels'][0]);
        $this->assertTrue($levels['controls_effort']);
    }

    public function test_thinking_levels_follow_the_model_variant(): void
    {
        $this->assertSame(['off', 'medium', 'high', 'xhigh'], $this->manager()->thinkingLevels('openai', 'gpt-5.2-pro')['levels']);
        $this->assertSame(['off', 'low', 'medium', 'high'], $this->manager()->thinkingLevels('gemini', 'gemini-3.1-pro-preview')['levels']);
    }

    public function test_thinking_levels_for_a_visibility_only_provider(): void
    {
        $expected = ['levels' => ['off', 'medium'], 'controls_effort' => false];

        $this->assertSame($expected, $this->manager()->thinkingLevels('openai-compatible', 'deepseek-r1'));
        $this->assertSame($expected, $this->manager()->thinkingLevels('lm-studio', 'qwen3'));
        $this->assertSame(
            ['levels' => ['off', 'low', 'medium', 'high'], 'controls_effort' => true],
            $this->manager()->thinkingLevels('lm-studio', 'qwen3', ['reasoning' => true]),
        );
    }

    public function test_thinking_levels_for_a_voice_provider_are_empty(): void
    {
        $this->assertSame(
            ['levels' => [], 'controls_effort' => false],
            $this->manager()->thinkingLevels(AiProvider::ElevenLabs, 'eleven_multilingual_v2'),
        );
    }

    // ------------------------------------------------------------ api key

    public function test_an_update_can_rotate_the_api_key(): void
    {
        $system = $this->manager()->create($this->validAttributes());

        $this->manager()->update($system, [
            'name' => 'Test System',
            'max_tokens' => 1024,
            'api_key' => 'sk-ant-rotated',
        ]);

        $this->assertSame('sk-ant-rotated', $system->fresh()->api_key);
    }

    public function test_an_update_that_omits_or_blanks_the_api_key_keeps_it(): void
    {
        foreach ([[], ['api_key' => null], ['api_key' => '']] as $extra) {
            $system = $this->manager()->create($this->validAttributes());

            $this->manager()->update($system, ['name' => 'Renamed', 'max_tokens' => 1024] + $extra);

            $this->assertSame('sk-ant-test', $system->fresh()->api_key);
            $this->assertSame('Renamed', $system->fresh()->name);
        }
    }

    public function test_an_update_keeps_a_local_systems_key_when_it_is_omitted(): void
    {
        // normalizeForPersistence() blanks a missing key for local providers;
        // on update that must not overwrite the stored one.
        $system = AiSystem::create([
            'name' => 'Local',
            'provider' => 'openai-compatible',
            'api_key' => 'local-secret',
            'model' => 'local-model',
            'max_tokens' => 1024,
            'base_url' => 'http://localhost:8080/v1',
        ]);

        $this->manager()->update($system, ['name' => 'Renamed', 'max_tokens' => 1024]);

        $this->assertSame('local-secret', $system->fresh()->api_key);
    }

    // ------------------------------------------------------------- delete

    public function test_deleting_a_system_deactivates_operators_and_releases_feature_claims(): void
    {
        $system = $this->manager()->create($this->validAttributes([
            'feature_defaults' => ['persona:support', 'operator:orders'],
        ]));

        $operator = AiOperator::create([
            'ai_system_id' => $system->id,
            'name' => 'Order Processor',
            'slug' => 'order-processor',
            'prompt_template' => 'Process it.',
            'is_active' => true,
        ]);

        $this->assertSame(0, $this->manager()->delete($system));

        $this->assertFalse($operator->fresh()->is_active);
        $this->assertSame(0, AiSystemFeatureDefault::where('ai_system_id', $system->id)->count());
        $this->assertSame([], $this->manager()->claimedFeatures());
        $this->assertSoftDeleted($system);
    }

    public function test_a_failed_delete_rolls_back_the_deactivations(): void
    {
        $system = $this->manager()->create($this->validAttributes([
            'feature_defaults' => ['persona:support'],
        ]));

        $persona = AiPersona::create([
            'ai_system_id' => $system->id,
            'name' => 'Bot',
            'slug' => 'bot',
            'prompt_template' => 'You are {{persona_name}}.',
            'is_active' => true,
        ]);

        AiSystem::deleting(static function (): void {
            throw new \RuntimeException('boom');
        });

        try {
            $this->manager()->delete($system);
            $this->fail('The delete should have thrown.');
        } catch (\RuntimeException) {
        }

        $this->assertTrue($persona->fresh()->is_active);
        $this->assertSame(['persona:support'], $this->manager()->claimedFeatures());
        $this->assertNotSoftDeleted($system);
    }

    // --------------------------------------------------------- json shapes

    /**
     * @return array<string, array{string, string}>
     */
    public static function fieldsThatAreNotObjects(): array
    {
        return [
            'config scalar' => ['config', 'true'],
            'config list' => ['config', '[1,2]'],
            'config provider_options string' => ['config', '{"provider_options":"x"}'],
            'config provider_options list' => ['config', '{"provider_options":[1]}'],
            'pricing_profile scalar' => ['pricing_profile', 'true'],
            'pricing_profile list' => ['pricing_profile', '[1,2]'],
        ];
    }

    #[DataProvider('fieldsThatAreNotObjects')]
    public function test_a_json_field_that_is_not_an_object_is_rejected(string $field, string $json): void
    {
        try {
            $this->manager()->create($this->validAttributes([$field => $json]));
            $this->fail("{$field} {$json} should have been rejected.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }

        $this->assertSame(0, AiSystem::count());
    }

    public function test_an_empty_provider_options_object_is_accepted(): void
    {
        $system = $this->manager()->create($this->validAttributes([
            'config' => '{"provider_options":{}}',
        ]));

        $this->assertSame(['provider_options' => []], $system->fresh()->config);
    }

    // ------------------------------------------------- base_url, temperature

    public function test_an_openai_compatible_system_requires_a_base_url(): void
    {
        try {
            $this->manager()->create([
                'name' => 'Local',
                'provider' => 'openai-compatible',
                'model' => 'local-model',
                'max_tokens' => 1024,
            ]);
            $this->fail('A missing base_url should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('base_url', $e->errors());
        }
    }

    public function test_an_openai_compatible_update_is_satisfied_by_the_stored_base_url(): void
    {
        $system = $this->manager()->create([
            'name' => 'Local',
            'provider' => 'openai-compatible',
            'model' => 'local-model',
            'max_tokens' => 1024,
            'base_url' => 'http://localhost:8080/v1',
        ]);

        $this->manager()->update($system, ['name' => 'Renamed', 'max_tokens' => 1024]);

        $this->assertSame('http://localhost:8080/v1', $system->fresh()->base_url);
    }

    public function test_a_temperature_up_to_two_is_accepted(): void
    {
        $system = $this->manager()->create($this->validAttributes(['temperature' => 1.5]));

        $this->assertEquals(1.5, $system->fresh()->temperature);
    }

    public function test_a_temperature_above_two_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->manager()->create($this->validAttributes(['temperature' => 2.1]));
    }

    // ------------------------------------------------- lm studio hydration

    private function lmStudioSystem(array $capabilities = ['reasoning' => true, 'vision' => false, 'tools' => true, 'max_context_length' => 4096]): AiSystem
    {
        return AiSystem::create([
            'name' => 'Local LM Studio',
            'provider' => 'lm-studio',
            'api_key' => 'lm-secret',
            'model' => 'qwen3',
            'max_tokens' => 1024,
            'base_url' => 'http://lmstudio.test:1234',
            'model_capabilities' => $capabilities,
        ]);
    }

    public function test_lm_studio_hydration_sends_the_stored_api_key(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        $system = $this->lmStudioSystem();

        $this->manager()->update($system, ['name' => 'Renamed', 'max_tokens' => 1024]);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/api/v1/models')
            && $request->hasHeader('Authorization', 'Bearer lm-secret'));

        // A refused listing is not a reason to fail the save or drop the flags.
        $this->assertSame('Renamed', $system->fresh()->name);
        $this->assertSame(4096, $system->fresh()->model_capabilities['max_context_length']);
    }

    public function test_an_unreachable_lm_studio_server_does_not_block_the_save(): void
    {
        Http::fake(['*' => static fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused')]);

        $system = $this->lmStudioSystem();
        $before = $system->fresh()->model_capabilities;

        $this->manager()->update($system, ['name' => 'Renamed', 'max_tokens' => 1024]);

        $this->assertSame('Renamed', $system->fresh()->name);
        $this->assertSame($before, $system->fresh()->model_capabilities);
    }

    public function test_an_lm_studio_hydration_failure_is_logged_with_its_credentials_masked(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        Http::fake(['*' => static fn () => throw new \Illuminate\Http\Client\ConnectionException(
            'cURL error 7: Failed to connect for http://user:pw-secret@lmstudio.test:1234/api/v1/models?token=t-secret'
        )]);

        $this->manager()->update($this->lmStudioSystem(), ['name' => 'Renamed', 'max_tokens' => 1024]);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => str_starts_with($message, 'Could not resolve LM Studio capabilities')
                && $context['exception'] === \Illuminate\Http\Client\ConnectionException::class
                && str_contains($context['error'], 'lmstudio.test:1234')
                && !str_contains(json_encode($context), 'pw-secret')
                && !str_contains(json_encode($context), 't-secret'),
        );
    }
}

<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Models\AiSystemFeatureDefault;
use Jvjvjv\CodeTalker\Models\AiSystemPrompt;
use Jvjvjv\CodeTalker\Services\Management\AiSystemManager;
use Jvjvjv\CodeTalker\Tests\TestCase;

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
            AiChatBot::create([
                'ai_system_id' => $system->id,
                'name' => "Bot {$slug}",
                'slug' => $slug,
                'prompt_template' => 'You are {{bot_name}}.',
                'is_active' => true,
            ]);
        }

        $deactivated = $this->manager()->delete($system);

        $this->assertSame(2, $deactivated);
        $this->assertSame(0, AiChatBot::where('is_active', true)->count());
        // Deactivated, not deleted — the relationship and its history survive.
        $this->assertSame(2, AiChatBot::count());
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
            'feature_defaults' => ['chat-bot:support'],
        ]));

        $clone = $this->manager()->duplicate($system);

        $this->assertSame('Test System (copy)', $clone->name);
        $this->assertNotSame($system->id, $clone->id);

        // A feature has one default system; copying the claims would silently
        // steal them from the original.
        $this->assertSame(
            $system->id,
            AiSystemFeatureDefault::where('feature', 'chat-bot:support')->value('ai_system_id')
        );
    }

    public function test_duplicating_can_copy_feature_defaults_when_asked(): void
    {
        $system = $this->manager()->create($this->validAttributes([
            'feature_defaults' => ['chat-bot:support'],
        ]));

        $clone = $this->manager()->duplicate($system, copyFeatureDefaults: true);

        $this->assertSame(
            $clone->id,
            AiSystemFeatureDefault::where('feature', 'chat-bot:support')->value('ai_system_id')
        );
    }

    public function test_claiming_a_feature_takes_it_from_the_system_that_held_it(): void
    {
        $first = $this->manager()->create($this->validAttributes([
            'feature_defaults' => ['chat-bot:support'],
        ]));

        $second = $this->manager()->create($this->validAttributes([
            'name' => 'Second System',
            'feature_defaults' => ['chat-bot:support'],
        ]));

        $this->assertSame(1, AiSystemFeatureDefault::where('feature', 'chat-bot:support')->count());
        $this->assertSame(
            $second->id,
            AiSystemFeatureDefault::where('feature', 'chat-bot:support')->value('ai_system_id')
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
}

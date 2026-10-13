<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Jvjvjv\CodeTalker\Enums\AiSystemRole;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Models\AiSystemFeatureDefault;
use Jvjvjv\CodeTalker\Services\AiSystemResolver;
use Jvjvjv\CodeTalker\Services\LaravelAi\AgentFactory;
use Jvjvjv\CodeTalker\Tests\TestCase;
use RuntimeException;

class AiSystemResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    private function systemFor(string $feature, array $attributes = []): AiSystem
    {
        $system = AiSystem::create(array_merge([
            'name' => 'System',
            'provider' => 'openai',
            'api_key' => 'sk-test',
            'model' => 'gpt-4o',
        ], $attributes));

        AiSystemFeatureDefault::create(['ai_system_id' => $system->id, 'feature' => $feature]);

        return $system;
    }

    private function resolver(): AiSystemResolver
    {
        return $this->app->make(AiSystemResolver::class);
    }

    public function test_a_feature_resolves_to_its_default_system(): void
    {
        $system = $this->systemFor('voice:greeting', ['role' => 'audio']);

        $this->assertTrue($system->is($this->resolver()->forFeature('voice:greeting', AiSystemRole::Audio)));
    }

    public function test_a_feature_with_no_default_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No default AI system configured for feature: voice:greeting');

        $this->resolver()->forFeature('voice:greeting', AiSystemRole::Audio);
    }

    public function test_an_inactive_default_is_refused(): void
    {
        $this->systemFor('voice:greeting', ['role' => 'audio', 'is_active' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The default AI system for feature 'voice:greeting' is inactive.");

        $this->resolver()->forFeature('voice:greeting', AiSystemRole::Audio);
    }

    public function test_a_default_with_the_wrong_role_is_refused_naming_both_roles(): void
    {
        $this->systemFor('voice:greeting');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "The default AI system for feature 'voice:greeting' has the 'chat' role, but 'audio' is required."
        );

        $this->resolver()->forFeature('voice:greeting', AiSystemRole::Audio);
    }

    public function test_the_agent_factory_still_resolves_a_chat_feature(): void
    {
        $system = $this->systemFor('persona:support');

        $this->assertTrue($system->is($this->app->make(AgentFactory::class)->systemForFeature('persona:support')));
    }

    public function test_the_agent_factory_keeps_its_missing_default_message(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No default AI system configured for feature: persona:support');

        $this->app->make(AgentFactory::class)->systemForFeature('persona:support');
    }

    public function test_the_agent_factory_keeps_its_inactive_message(): void
    {
        $this->systemFor('persona:support', ['is_active' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The default AI system for feature 'persona:support' is inactive.");

        $this->app->make(AgentFactory::class)->systemForFeature('persona:support');
    }

    public function test_the_agent_factory_refuses_a_chat_feature_mapped_to_an_audio_system(): void
    {
        $this->systemFor('persona:support', ['provider' => 'elevenlabs', 'model' => '', 'role' => 'audio']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("has the 'audio' role, but 'chat' is required");

        $this->app->make(AgentFactory::class)->systemForFeature('persona:support');
    }
}

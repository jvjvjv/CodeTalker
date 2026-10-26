<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Jvjvjv\CodeTalker\Models\AiPersona;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\Management\AiPersonaManager;
use Jvjvjv\CodeTalker\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AiPersonaManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    public function makeSystem(array $overrides = []): AiSystem
    {
        return AiSystem::create(array_merge([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ], $overrides));
    }

    private function manager(): AiPersonaManager
    {
        return $this->app->make(AiPersonaManager::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(AiSystem $system, array $overrides = []): array
    {
        return array_merge([
            'ai_system_id' => $system->id,
            'name' => 'Support Bot',
            'slug' => 'support-bot',
            'access_path' => AiPersona::ACCESS_PATH_CHAT,
            'prompt_template' => 'You are {{bot_name}}.',
        ], $overrides);
    }

    public function test_a_persona_on_an_active_chat_system_is_created(): void
    {
        $persona = $this->manager()->create($this->attributes($this->makeSystem()));

        $this->assertTrue($persona->exists);
    }

    /**
     * @return array<string, array{\Closure(AiPersonaManagerTest): AiSystem}>
     */
    public static function unusableSystems(): array
    {
        return [
            'soft-deleted' => [static function (self $test): AiSystem {
                $system = $test->makeSystem();
                $system->delete();

                return $system;
            }],
            'inactive' => [static fn (self $test): AiSystem => $test->makeSystem(['is_active' => false])],
            'audio role' => [static fn (self $test): AiSystem => $test->makeSystem([
                'provider' => 'openai',
                'role' => 'audio',
                'model' => '',
            ])],
        ];
    }

    #[DataProvider('unusableSystems')]
    public function test_a_persona_on_an_unusable_system_is_rejected(\Closure $makeSystem): void
    {
        $system = $makeSystem($this);

        try {
            $this->manager()->create($this->attributes($system));
            $this->fail('An unusable system should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ai_system_id', $e->errors());
        }

        $this->assertSame(0, AiPersona::withoutGlobalScopes()->count());
    }

    public function test_a_temperature_up_to_two_is_accepted(): void
    {
        $persona = $this->manager()->create($this->attributes($this->makeSystem(), ['temperature' => 1.5]));

        $this->assertEquals(1.5, $persona->fresh()->temperature);
    }

    public function test_a_temperature_above_two_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->manager()->create($this->attributes($this->makeSystem(), ['temperature' => 2.5]));
    }
}

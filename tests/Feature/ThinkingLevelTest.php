<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Jvjvjv\CodeTalker\Enums\ThinkingLevel;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Tests\TestCase;

class ThinkingLevelTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    private function migration(): object
    {
        return require __DIR__ . '/../../database/migrations/2026_10_24_000001_add_thinking_level_to_ai_systems_table.php';
    }

    private function insertSystem(?bool $enableThinking): int
    {
        return DB::table('ai_systems')->insertGetId([
            'name' => 'System',
            'provider' => 'anthropic',
            'api_key' => 'secret',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 4096,
            'enable_thinking' => $enableThinking,
        ]);
    }

    public function test_levels_are_declared_in_ascending_order(): void
    {
        $this->assertSame(
            ['off', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'],
            ThinkingLevel::values(),
        );

        foreach (ThinkingLevel::cases() as $index => $level) {
            $this->assertSame($index, $level->ordinal());
        }
    }

    public function test_the_legacy_flag_maps_onto_the_scale(): void
    {
        $this->assertSame(ThinkingLevel::Medium, ThinkingLevel::fromLegacyFlag(true));
        $this->assertSame(ThinkingLevel::Off, ThinkingLevel::fromLegacyFlag(false));
        $this->assertNull(ThinkingLevel::fromLegacyFlag(null));
    }

    public function test_the_migration_backfills_levels_from_the_legacy_flag(): void
    {
        $this->migration()->down();

        $on = $this->insertSystem(true);
        $off = $this->insertSystem(false);
        $unset = $this->insertSystem(null);

        $this->migration()->up();

        $levels = DB::table('ai_systems')->pluck('thinking_level', 'id');

        $this->assertSame('medium', $levels[$on]);
        $this->assertSame('off', $levels[$off]);
        $this->assertNull($levels[$unset]);
    }

    public function test_a_stored_level_reads_back_as_the_enum(): void
    {
        $system = AiSystem::create([
            'name' => 'System',
            'provider' => 'openai',
            'api_key' => 'sk-test',
            'model' => 'gpt-5',
            'thinking_level' => 'high',
        ]);

        $this->assertSame(ThinkingLevel::High, $system->fresh()->thinking_level);
        $this->assertSame(ThinkingLevel::High, $system->fresh()->thinkingLevel());
    }

    public function test_a_system_without_a_level_falls_back_to_the_legacy_flag(): void
    {
        $this->assertSame(ThinkingLevel::Medium, (new AiSystem(['enable_thinking' => true]))->thinkingLevel());
        $this->assertSame(ThinkingLevel::Off, (new AiSystem(['enable_thinking' => false]))->thinkingLevel());
        $this->assertNull((new AiSystem())->thinkingLevel());
    }

    public function test_a_level_wins_over_the_legacy_flag(): void
    {
        $system = new AiSystem(['enable_thinking' => true, 'thinking_level' => 'off']);

        $this->assertSame(ThinkingLevel::Off, $system->thinkingLevel());
    }

    public function test_a_partial_select_without_the_level_column_still_resolves(): void
    {
        $id = $this->insertSystem(true);

        $partial = AiSystem::query()->select(['id', 'name', 'enable_thinking'])->findOrFail($id);

        $this->assertSame(ThinkingLevel::Medium, $partial->thinkingLevel());
    }
}

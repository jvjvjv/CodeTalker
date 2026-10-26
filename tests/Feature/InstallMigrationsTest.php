<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\Management\AiSystemPromptManager;
use Jvjvjv\CodeTalker\Tests\TestCase;

class InstallMigrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    private function makeSystem(): AiSystem
    {
        return AiSystem::create([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);
    }

    private function uuidMigration(): object
    {
        return require __DIR__ . '/../../database/migrations/2026_10_25_000001_add_uuid_to_ai_conversations_table.php';
    }

    private function sequenceMigration(): object
    {
        return require __DIR__ . '/../../database/migrations/2026_10_25_000002_advance_ai_system_prompts_sequence.php';
    }

    public function test_the_package_migrations_create_the_conversation_uuid(): void
    {
        $this->assertTrue(Schema::hasColumn('ai_conversations', 'uuid'));

        $conversation = AiConversation::create([
            'ai_system_id' => $this->makeSystem()->id,
            'feature' => 'persona:test',
        ]);

        $this->assertNotEmpty($conversation->fresh()->uuid);
    }

    public function test_the_migration_backfills_rows_without_a_uuid(): void
    {
        $system = $this->makeSystem();
        $ids = [];

        foreach (range(1, 3) as $_) {
            $ids[] = DB::table('ai_conversations')->insertGetId([
                'ai_system_id' => $system->id,
                'feature' => 'persona:test',
                'uuid' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->uuidMigration()->up();

        $uuids = DB::table('ai_conversations')->whereIn('id', $ids)->pluck('uuid')->all();

        $this->assertCount(3, array_filter($uuids));
        $this->assertCount(3, array_unique($uuids));
    }

    public function test_the_migration_unscopes_visitor_email_from_user_memories(): void
    {
        DB::table('ai_feature_memories')->insert([
            'feature' => 'persona:test',
            'category' => 'preference',
            'key' => 'units',
            'content' => 'Prefers metric units.',
            'user_id' => '7',
            'visitor_email' => 'someone@example.com',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->uuidMigration()->up();

        $this->assertNull(DB::table('ai_feature_memories')->value('visitor_email'));
        $this->assertSame('7', DB::table('ai_feature_memories')->value('user_id'));
    }

    public function test_a_conversation_without_a_uuid_gets_a_distinct_chat_hash(): void
    {
        $system = $this->makeSystem();
        $hashes = [];

        foreach (range(1, 2) as $_) {
            $id = DB::table('ai_conversations')->insertGetId([
                'ai_system_id' => $system->id,
                'feature' => 'persona:test',
                'uuid' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $hashes[] = AiConversation::find($id)->generateChatHash();
        }

        $this->assertNotSame(md5(''), $hashes[0]);
        $this->assertNotSame($hashes[0], $hashes[1]);
    }

    public function test_the_prompt_sequence_is_advanced_on_postgres(): void
    {
        // Pretend mode compiles the statement against a pgsql connection
        // without connecting.
        config()->set('database.connections.pretend_pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'database' => 'pretend',
            'username' => 'pretend',
            'password' => '',
            'prefix' => '',
        ]);

        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pretend_pgsql');

        try {
            $queries = DB::connection()->pretend(fn () => $this->sequenceMigration()->up());
        } finally {
            DB::setDefaultConnection($original);
        }

        $this->assertSame(
            ["select setval(pg_get_serial_sequence('ai_system_prompts', 'id'), coalesce((select max(id) from ai_system_prompts), 1))"],
            array_column($queries, 'query'),
        );
    }

    public function test_the_prompt_sequence_migration_is_a_no_op_elsewhere(): void
    {
        $queries = DB::connection()->pretend(fn () => $this->sequenceMigration()->up());

        $this->assertSame([], $queries);
    }

    public function test_a_prompt_can_be_created_after_the_seeded_defaults(): void
    {
        $prompt = $this->app->make(AiSystemPromptManager::class)->create([
            'title' => 'Support',
            'description' => 'Support prompt.',
            'content' => 'You help customers.',
        ]);

        $this->assertGreaterThan(3, $prompt->id);
    }
}

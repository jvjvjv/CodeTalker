<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiFeatureMemory;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Models\AiSystemPrompt;
use Jvjvjv\CodeTalker\Services\Management\AiChatBotManager;
use Jvjvjv\CodeTalker\Services\Management\AiConversationManager;
use Jvjvjv\CodeTalker\Services\Management\AiMemoryManager;
use Jvjvjv\CodeTalker\Services\Management\AiSystemPromptManager;
use Jvjvjv\CodeTalker\Tests\TestCase;

class ManagementServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // AiConversation::booted() assigns a uuid, but no package migration
        // creates the column (host apps add it themselves).
        if (!Schema::hasColumn('ai_conversations', 'uuid')) {
            Schema::table('ai_conversations', function ($table): void {
                $table->string('uuid')->nullable();
            });
        }

        // Testbench's migrations do not create a `users` table, and conversation
        // search joins one to match on the participant's name and email.
        if (!Schema::hasTable('users')) {
            Schema::create('users', function ($table): void {
                $table->id();
                $table->string('name');
                $table->string('email');
            });
        }
    }

    private function makeSystem(array $overrides = []): AiSystem
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

    private function makeBot(AiSystem $system, array $overrides = []): AiChatBot
    {
        return AiChatBot::create(array_merge([
            'ai_system_id' => $system->id,
            'name' => 'Test Bot',
            'slug' => 'test-bot',
            'prompt_template' => 'You are {{bot_name}}.',
            'is_active' => true,
        ], $overrides));
    }

    // ---------------------------------------------------------------- prompts

    public function test_deleting_a_prompt_clears_references_and_reports_the_count(): void
    {
        $prompt = AiSystemPrompt::create([
            'title' => 'Shared',
            'description' => 'Shared prompt',
            'content' => 'Body.',
        ]);

        $this->makeSystem(['system_prompt_id' => $prompt->id]);
        $this->makeSystem(['name' => 'Second', 'system_prompt_id' => $prompt->id]);

        $affected = $this->app->make(AiSystemPromptManager::class)->delete($prompt);

        $this->assertSame(2, $affected);
        $this->assertSame(0, AiSystem::whereNotNull('system_prompt_id')->count());
        $this->assertNull(AiSystemPrompt::find($prompt->id));
    }

    public function test_deleting_an_unreferenced_prompt_reports_zero(): void
    {
        $prompt = AiSystemPrompt::create([
            'title' => 'Lonely',
            'description' => 'Unused prompt',
            'content' => 'Body.',
        ]);

        $this->assertSame(0, $this->app->make(AiSystemPromptManager::class)->delete($prompt));
    }

    // --------------------------------------------------------------- chat bots

    public function test_bot_listing_aggregates_lifetime_usage(): void
    {
        $system = $this->makeSystem();
        $bot = $this->makeBot($system);

        foreach ([[100, 20, '0.50'], [50, 10, '0.25']] as [$in, $out, $cost]) {
            AiConversation::create([
                'ai_system_id' => $system->id,
                'ai_chat_bot_id' => $bot->id,
                'feature' => 'chat-bot:test-bot',
                'usage_input_tokens' => $in,
                'usage_output_tokens' => $out,
                'usage_cost_usd' => $cost,
            ]);
        }

        $listed = $this->app->make(AiChatBotManager::class)->listWithUsage();

        $this->assertCount(1, $listed);
        $this->assertSame(2, $listed[0]['conversations_count']);
        $this->assertSame(150, $listed[0]['usage']['input_tokens']);
        $this->assertSame(30, $listed[0]['usage']['output_tokens']);
        $this->assertSame(0.75, $listed[0]['usage']['cost_usd']);
        // Neither is meaningful across an aggregate.
        $this->assertNull($listed[0]['usage']['total_tokens']);
        $this->assertNull($listed[0]['usage']['synced_at']);
    }

    public function test_usage_is_null_when_no_conversation_has_a_recorded_cost(): void
    {
        $system = $this->makeSystem();
        $this->makeBot($system);

        $listed = $this->app->make(AiChatBotManager::class)->listWithUsage();

        $this->assertNull($listed[0]['usage']);
    }

    public function test_bot_listing_can_be_scoped_to_one_system(): void
    {
        $first = $this->makeSystem();
        $second = $this->makeSystem(['name' => 'Second']);

        $this->makeBot($first, ['slug' => 'first-bot']);
        $this->makeBot($second, ['slug' => 'second-bot', 'name' => 'Second Bot']);

        $listed = $this->app->make(AiChatBotManager::class)->listWithUsage($second->id);

        $this->assertCount(1, $listed);
        $this->assertSame('second-bot', $listed[0]['slug']);
    }

    public function test_a_reserved_slug_is_rejected_only_from_the_root_path(): void
    {
        $system = $this->makeSystem();
        $manager = $this->app->make(AiChatBotManager::class);

        $attributes = [
            'name' => 'Admin Bot',
            'slug' => 'admin',
            'ai_system_id' => $system->id,
            'prompt_template' => 'You are {{bot_name}}.',
        ];

        // Under /chat/ there is no conflict with a host route.
        $bot = $manager->create($attributes + ['access_path' => AiChatBot::ACCESS_PATH_CHAT]);
        $this->assertSame('admin', $bot->slug);

        $this->expectException(ValidationException::class);

        $manager->create(array_merge($attributes, [
            'slug' => 'login',
            'access_path' => AiChatBot::ACCESS_PATH_ROOT,
        ]));
    }

    public function test_a_bot_can_keep_its_own_slug_on_update(): void
    {
        $system = $this->makeSystem();
        $bot = $this->makeBot($system);

        $updated = $this->app->make(AiChatBotManager::class)->update($bot, [
            'name' => 'Renamed Bot',
            'slug' => 'test-bot',
            'access_path' => AiChatBot::ACCESS_PATH_CHAT,
            'ai_system_id' => $system->id,
            'prompt_template' => 'You are {{bot_name}}.',
        ]);

        $this->assertSame('Renamed Bot', $updated->name);
    }

    public function test_tool_listing_respects_the_systems_allow_list(): void
    {
        $system = $this->makeSystem(['allowed_tools' => ['fetch-web-page']]);
        $manager = $this->app->make(AiChatBotManager::class);

        $scoped = $manager->availableTools($system->id);
        $this->assertSame(['fetch-web-page'], array_column($scoped, 'name'));

        // include_all overrides the allow-list entirely.
        $all = $manager->availableTools($system->id, includeAll: true);
        $this->assertEqualsCanonicalizing(
            ['fetch-web-page', 'http-request', 'get-temporal-information', 'search-web', 'scan-memories'],
            array_column($all, 'name'),
        );
    }

    // ----------------------------------------------------------- conversations

    private function makeConversation(AiChatBot $bot, array $overrides = []): AiConversation
    {
        return AiConversation::create(array_merge([
            'ai_system_id' => $bot->ai_system_id,
            'ai_chat_bot_id' => $bot->id,
            'feature' => 'chat-bot:test-bot',
            'title' => 'A conversation',
        ], $overrides));
    }

    public function test_message_counts_exclude_system_messages(): void
    {
        $bot = $this->makeBot($this->makeSystem());
        $conversation = $this->makeConversation($bot);

        foreach (['system', 'user', 'assistant'] as $role) {
            AiConversationMessage::create([
                'ai_conversation_id' => $conversation->id,
                'role' => $role,
                'content' => "A {$role} message",
            ]);
        }

        $page = $this->app->make(AiConversationManager::class)->paginate();

        $this->assertSame(2, $page->items()[0]['messages_count']);
    }

    public function test_conversations_can_be_filtered(): void
    {
        $system = $this->makeSystem();
        $other = $this->makeSystem(['name' => 'Other']);
        $bot = $this->makeBot($system);

        $this->makeConversation($bot, ['feature' => 'chat-bot:alpha']);
        $this->makeConversation($bot, ['feature' => 'chat-bot:beta', 'ai_system_id' => $other->id]);

        $manager = $this->app->make(AiConversationManager::class);

        $this->assertCount(1, $manager->paginate(['feature' => 'chat-bot:alpha'])->items());
        $this->assertCount(1, $manager->paginate(['ai_system_id' => $other->id])->items());
        $this->assertCount(2, $manager->paginate()->items());
    }

    public function test_search_matches_titles_visitors_and_message_bodies(): void
    {
        $bot = $this->makeBot($this->makeSystem());
        $manager = $this->app->make(AiConversationManager::class);

        $byTitle = $this->makeConversation($bot, ['title' => 'Unique title here']);
        $byVisitor = $this->makeConversation($bot, ['title' => 'Other', 'visitor_email' => 'zebra@example.test']);
        $byMessage = $this->makeConversation($bot, ['title' => 'Third']);

        AiConversationMessage::create([
            'ai_conversation_id' => $byMessage->id,
            'role' => 'user',
            'content' => 'A pangolin appeared',
        ]);

        $this->assertSame($byTitle->id, $manager->paginate(['search' => 'Unique title'])->items()[0]['id']);
        $this->assertSame($byVisitor->id, $manager->paginate(['search' => 'zebra'])->items()[0]['id']);
        $this->assertSame($byMessage->id, $manager->paginate(['search' => 'pangolin'])->items()[0]['id']);
    }

    public function test_search_ignores_system_message_content(): void
    {
        $bot = $this->makeBot($this->makeSystem());
        $conversation = $this->makeConversation($bot, ['title' => 'Plain']);

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'system',
            'content' => 'A secret marker in the system prompt',
        ]);

        $found = $this->app->make(AiConversationManager::class)->paginate(['search' => 'secret marker']);

        $this->assertCount(0, $found->items());
    }

    public function test_detail_returns_messages_in_order_with_correlated_memories(): void
    {
        $bot = $this->makeBot($this->makeSystem());
        $conversation = $this->makeConversation($bot);

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'First',
            'created_at' => now()->subMinute(),
        ]);
        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Second',
            'created_at' => now(),
        ]);

        foreach ([60, 90] as $confidence) {
            AiFeatureMemory::create([
                'feature' => 'chat-bot:test-bot',
                'category' => 'preference',
                'key' => "key-{$confidence}",
                'content' => 'Something learned',
                'confidence' => $confidence,
                'source_conversation_id' => $conversation->id,
                'is_active' => true,
            ]);
        }

        $detail = $this->app->make(AiConversationManager::class)->detail($conversation);

        $this->assertSame(['First', 'Second'], $detail['messages']->pluck('content')->all());
        $this->assertSame([90, 60], $detail['memories']->pluck('confidence')->all());
    }

    // -------------------------------------------------------------- memories

    public function test_memories_are_listed_active_first_then_by_confidence(): void
    {
        foreach ([
            ['key' => 'inactive-high', 'confidence' => 95, 'is_active' => false],
            ['key' => 'active-low', 'confidence' => 20, 'is_active' => true],
            ['key' => 'active-high', 'confidence' => 80, 'is_active' => true],
        ] as $attributes) {
            AiFeatureMemory::create(array_merge([
                'feature' => 'chat-bot:test-bot',
                'category' => 'preference',
                'content' => 'Something learned',
            ], $attributes));
        }

        $page = $this->app->make(AiMemoryManager::class)->paginate();

        $this->assertSame(
            ['active-high', 'active-low', 'inactive-high'],
            $page->getCollection()->pluck('key')->all(),
        );
    }

    public function test_memories_can_be_filtered_by_status_and_category(): void
    {
        AiFeatureMemory::create([
            'feature' => 'f', 'category' => 'preference', 'key' => 'a',
            'content' => 'x', 'confidence' => 50, 'is_active' => true,
        ]);
        AiFeatureMemory::create([
            'feature' => 'f', 'category' => 'domain_knowledge', 'key' => 'b',
            'content' => 'x', 'confidence' => 50, 'is_active' => false,
        ]);

        $manager = $this->app->make(AiMemoryManager::class);

        $this->assertCount(1, $manager->paginate(['status' => 'active'])->items());
        $this->assertCount(1, $manager->paginate(['category' => 'domain_knowledge'])->items());
    }

    public function test_a_memorys_feature_cannot_be_changed_on_update(): void
    {
        $memory = AiFeatureMemory::create([
            'feature' => 'original', 'category' => 'preference', 'key' => 'a',
            'content' => 'x', 'confidence' => 50, 'is_active' => true,
        ]);

        $this->app->make(AiMemoryManager::class)->update($memory, [
            'feature' => 'changed',
            'category' => 'preference',
            'key' => 'a',
            'content' => 'y',
            'confidence' => 60,
        ]);

        $this->assertSame('original', $memory->fresh()->feature);
        $this->assertSame('y', $memory->fresh()->content);
    }
}

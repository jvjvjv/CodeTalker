<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiChatBotConversationService;
use Jvjvjv\CodeTalker\Tests\TestCase;

/**
 * Characterization tests pinning the exact Inertia component and prop set the
 * chat-bot pages render. These exist so the ChatBotController decomposition
 * cannot quietly rename, drop, or unify a prop — in particular the deliberate
 * differences between show() and showByHash().
 */
class ChatBotPagePropsTest extends TestCase
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
    }

    private function makeBot(string $slug = 'alpha', array $botAttributes = []): AiChatBot
    {
        $system = AiSystem::create([
            'name' => 'Test System ' . $slug,
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);

        return AiChatBot::create(array_merge([
            'ai_system_id' => $system->id,
            'name' => 'Bot ' . $slug,
            'description' => 'Description for ' . $slug,
            'slug' => $slug,
            'access_path' => AiChatBot::ACCESS_PATH_CHAT,
            'prompt_template' => 'You are {{bot_name}}.',
            'is_active' => true,
        ], $botAttributes));
    }

    /**
     * Testbench's migrations do not create a `users` table, and nothing here
     * needs one: conversations are only ever queried by `user_id`.
     */
    private function makeUser(int $id = 1): User
    {
        $user = new User();
        $user->id = $id;
        $user->exists = true;

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function props(\Illuminate\Testing\TestResponse $response): array
    {
        return $response->json('props');
    }

    public function test_show_renders_the_expected_component_and_prop_set(): void
    {
        $bot = $this->makeBot();

        $response = $this->get('/chat/' . $bot->slug, ['X-Inertia' => 'true']);

        $response->assertOk()->assertJsonPath('component', 'ai/ChatBot');

        $props = $this->props($response);

        $this->assertSame([
            'bot',
            'chatUrl',
            'chatUrlBase',
            'history',
            'messageUrl',
            'messages',
            'resetUrl',
            'showIdentityForm',
            'statusUrl',
            'switchUrl',
            'warmupUrl',
        ], collect(array_keys($props))->sort()->values()->all());

        $this->assertArrayNotHasKey(
            'chatHash',
            $props,
            'show() must not expose chatHash — only showByHash() does.',
        );

        $this->assertSame([
            'name' => 'Bot alpha',
            'description' => 'Description for alpha',
            'require_visitor_identity' => false,
            'total_cost_usd' => 0,
        ], $props['bot']);

        $this->assertSame([], $props['messages']);
        $this->assertSame([], $props['history']);
        $this->assertNull($props['chatUrl']);
        $this->assertSame('/chat/alpha/', $props['chatUrlBase']);
        $this->assertFalse($props['showIdentityForm']);

        $this->assertSame(route('chat-bots.chat.message', $bot), $props['messageUrl']);
        $this->assertSame(route('chat-bots.chat.reset', $bot), $props['resetUrl']);
        $this->assertSame(route('chat-bots.chat.switch', $bot), $props['switchUrl']);
        $this->assertSame(route('chat-bots.chat.status', $bot), $props['statusUrl']);
        $this->assertSame(route('chat-bots.chat.warmup', $bot), $props['warmupUrl']);
    }

    public function test_show_maps_stored_conversation_messages_and_chat_url(): void
    {
        $bot = $this->makeBot();

        $conversation = $this->app->make(AiChatBotConversationService::class)->startConversation($bot);
        $conversation->generateChatHash();

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hello there',
        ]);
        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'General Kenobi',
            'reasoning_content' => 'thinking out loud',
            'blocks' => [['type' => 'text', 'content' => 'General Kenobi']],
        ]);

        $response = $this
            ->withCookie('ai_chat_bot_current', $conversation->public_id)
            ->get('/chat/' . $bot->slug, ['X-Inertia' => 'true']);

        $props = $this->props($response->assertOk());

        // The system message is excluded; the rest keep exactly these four keys.
        $this->assertSame([
            [
                'role' => 'user',
                'content' => 'Hello there',
                'reasoning_content' => null,
                'blocks' => null,
            ],
            [
                'role' => 'assistant',
                'content' => 'General Kenobi',
                'reasoning_content' => 'thinking out loud',
                'blocks' => [['type' => 'text', 'content' => 'General Kenobi']],
            ],
        ], $props['messages']);

        $this->assertSame('/chat/alpha/' . $conversation->fresh()->chat_hash, $props['chatUrl']);
    }

    public function test_show_requires_visitor_identity_only_without_a_conversation(): void
    {
        $bot = $this->makeBot('gated', ['require_visitor_identity' => true]);

        $response = $this->get('/chat/' . $bot->slug, ['X-Inertia' => 'true']);
        $this->assertTrue($this->props($response->assertOk())['showIdentityForm']);

        $conversation = $this->app->make(AiChatBotConversationService::class)->startConversation($bot);

        // The first request already seeded per-bot session state, and session
        // state takes precedence over the cookie fallback — so start clean.
        $this->flushSession();

        $response = $this
            ->withCookie('ai_chat_bot_current', $conversation->public_id)
            ->get('/chat/' . $bot->slug, ['X-Inertia' => 'true']);

        $this->assertFalse(
            $this->props($response->assertOk())['showIdentityForm'],
            'A stored conversation suppresses the identity form in show().',
        );
    }

    public function test_show_by_hash_adds_chat_hash_and_derives_identity_form_from_message_count(): void
    {
        $bot = $this->makeBot('gated', ['require_visitor_identity' => true]);

        $conversation = $this->app->make(AiChatBotConversationService::class)->startConversation($bot);
        $hash = $conversation->generateChatHash();

        $response = $this->get('/chat/' . $bot->slug . '/' . $hash, ['X-Inertia' => 'true']);

        $response->assertOk()->assertJsonPath('component', 'ai/ChatBot');

        $props = $this->props($response);

        $this->assertSame([
            'bot',
            'chatHash',
            'chatUrl',
            'chatUrlBase',
            'history',
            'messageUrl',
            'messages',
            'resetUrl',
            'showIdentityForm',
            'statusUrl',
            'switchUrl',
            'warmupUrl',
        ], collect(array_keys($props))->sort()->values()->all());

        $this->assertSame($hash, $props['chatHash']);
        $this->assertSame('/chat/gated/' . $hash, $props['chatUrl']);

        // Unlike show(), the by-hash page keys the identity form off the
        // conversation's non-system message count, not its mere existence.
        $this->assertTrue($props['showIdentityForm']);

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hi',
        ]);

        $response = $this->get('/chat/' . $bot->slug . '/' . $hash, ['X-Inertia' => 'true']);

        $this->assertFalse($this->props($response->assertOk())['showIdentityForm']);
    }

    public function test_show_by_hash_restores_the_conversation_into_session_history(): void
    {
        $bot = $this->makeBot();

        $conversation = $this->app->make(AiChatBotConversationService::class)->startConversation($bot);
        $hash = $conversation->generateChatHash();

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hi',
        ]);

        $props = $this->props(
            $this->get('/chat/' . $bot->slug . '/' . $hash, ['X-Inertia' => 'true'])->assertOk()
        );

        $this->assertCount(1, $props['history']);
        $this->assertSame([
            'handle',
            'label',
            'is_current',
            'is_stale',
            'updated_at',
            'cost_usd',
        ], array_keys($props['history'][0]));
        $this->assertTrue($props['history'][0]['is_current']);
        $this->assertSame('New chat', $props['history'][0]['label']);
    }

    public function test_index_renders_bots_without_conversations_for_a_guest(): void
    {
        $bot = $this->makeBot();

        $response = $this->get('/chats', ['X-Inertia' => 'true']);

        $response->assertOk()->assertJsonPath('component', 'ai/ChatBotsIndex');

        $props = $this->props($response);

        $this->assertSame(['bots'], array_keys($props));
        $this->assertCount(1, $props['bots']);

        $this->assertSame([
            'slug' => 'alpha',
            'name' => 'Bot alpha',
            'description' => 'Description for alpha',
            'new_chat_url' => route('chat-bots.chat.new', $bot),
            'status_url' => route('chat-bots.chat.status', $bot),
            'conversations' => [],
        ], $props['bots'][0]);
    }

    public function test_index_includes_conversations_for_an_authenticated_user(): void
    {
        $bot = $this->makeBot();
        $user = $this->makeUser();

        $conversation = $this->app->make(AiChatBotConversationService::class)
            ->startConversation($bot, $user);
        $conversation->forceFill(['title' => 'My chat'])->save();

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hi',
        ]);

        $props = $this->props(
            $this->actingAs($user)->get('/chats', ['X-Inertia' => 'true'])->assertOk()
        );

        $conversations = $props['bots'][0]['conversations'];

        $this->assertCount(1, $conversations);
        $this->assertSame(
            ['title', 'updated_at', 'updated_at_human', 'is_stale'],
            array_keys($conversations[0]),
        );
        $this->assertSame('My chat', $conversations[0]['title']);
        $this->assertFalse($conversations[0]['is_stale']);
    }

    public function test_component_names_come_from_config(): void
    {
        $bot = $this->makeBot();

        config()->set('code-talker.inertia.components.chat_bot', 'custom/Chat');
        config()->set('code-talker.inertia.components.chat_bots_index', 'custom/ChatIndex');

        $this->get('/chats', ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertJsonPath('component', 'custom/ChatIndex');

        $response = $this->get('/chat/' . $bot->slug, ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertJsonPath('component', 'custom/Chat');

        // Overriding the component must not disturb any prop.
        $this->assertSame([
            'bot',
            'chatUrl',
            'chatUrlBase',
            'history',
            'messageUrl',
            'messages',
            'resetUrl',
            'showIdentityForm',
            'statusUrl',
            'switchUrl',
            'warmupUrl',
        ], collect(array_keys($this->props($response)))->sort()->values()->all());
    }

    public function test_the_hash_page_uses_the_configured_chat_bot_component(): void
    {
        $bot = $this->makeBot();
        $conversation = $this->app->make(AiChatBotConversationService::class)->startConversation($bot);
        $hash = $conversation->generateChatHash();

        config()->set('code-talker.inertia.components.chat_bot', 'custom/Chat');

        $this->get('/chat/' . $bot->slug . '/' . $hash, ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertJsonPath('component', 'custom/Chat');
    }

    /**
     * A host that published `config/code-talker.php` before the `inertia` key
     * existed and then ran `config:cache` has no such key at all — Laravel skips
     * the package config merge when configuration is cached. The inline
     * fallbacks must carry that case, which otherwise fails in production only.
     */
    public function test_the_defaults_survive_a_config_without_the_inertia_key(): void
    {
        $bot = $this->makeBot();

        config()->set('code-talker.inertia', null);

        $this->get('/chats', ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertJsonPath('component', 'ai/ChatBotsIndex');

        $this->get('/chat/' . $bot->slug, ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertJsonPath('component', 'ai/ChatBot');
    }

    public function test_inaccessible_bots_abort_with_404(): void
    {
        $bot = $this->makeBot('inactive', ['is_active' => false]);

        $this->get('/chat/' . $bot->slug, ['X-Inertia' => 'true'])->assertNotFound();

        // A bot served at the root access path is not reachable under /chat.
        $rootBot = $this->makeBot('rooted', ['access_path' => AiChatBot::ACCESS_PATH_ROOT]);

        $this->get('/chat/' . $rootBot->slug, ['X-Inertia' => 'true'])->assertNotFound();
        $this->get('/' . $rootBot->slug, ['X-Inertia' => 'true'])->assertOk();
    }
}

<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\ChatBot\ConversationSessionStore;
use Jvjvjv\CodeTalker\Tests\TestCase;

class ConversationSessionStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasColumn('ai_conversations', 'uuid')) {
            Schema::table('ai_conversations', function ($table): void {
                $table->string('uuid')->nullable();
            });
        }
    }

    private function store(): ConversationSessionStore
    {
        return $this->app->make(ConversationSessionStore::class);
    }

    /**
     * @param array<string, string> $cookies
     */
    private function request(array $cookies = [], bool $secure = false): Request
    {
        $request = Request::create($secure ? 'https://example.test/chat' : 'http://example.test/chat', 'GET', [], $cookies);
        $request->setLaravelSession($this->app['session.store']);

        return $request;
    }

    private function makeBot(string $slug = 'alpha'): AiChatBot
    {
        $system = AiSystem::create([
            'name' => 'System ' . $slug,
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);

        return AiChatBot::create([
            'ai_system_id' => $system->id,
            'name' => 'Bot ' . $slug,
            'slug' => $slug,
            'access_path' => AiChatBot::ACCESS_PATH_CHAT,
            'prompt_template' => 'You are {{bot_name}}.',
            'is_active' => true,
        ]);
    }

    private function makeConversation(AiChatBot $bot, ?int $userId = null): AiConversation
    {
        return AiConversation::create([
            'ai_system_id' => $bot->ai_system_id,
            'ai_chat_bot_id' => $bot->id,
            'user_id' => $userId,
            'feature' => $bot->featureKey(),
        ]);
    }

    private function queuedCookie(string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach (Cookie::getQueuedCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    public function test_it_forgets_legacy_per_bot_cookies(): void
    {
        $request = $this->request([
            'ai_chat_bot_conversations_5' => 'stale',
            'ai_chat_bot_conversations_16' => 'also-stale',
            'unrelated_cookie' => 'keep-me',
        ]);

        $this->store()->forgetLegacyCookies($request);

        foreach (['ai_chat_bot_conversations_5', 'ai_chat_bot_conversations_16'] as $name) {
            $cookie = $this->queuedCookie($name);
            $this->assertNotNull($cookie, "{$name} should have been queued for deletion.");
            $this->assertTrue($cookie->getExpiresTime() < time());
        }

        $this->assertNull($this->queuedCookie('unrelated_cookie'));
    }

    public function test_state_falls_back_to_the_current_cookie_only_once(): void
    {
        $bot = $this->makeBot();
        $conversation = $this->makeConversation($bot);

        $request = $this->request(['ai_chat_bot_current' => $conversation->public_id]);

        $this->assertSame($conversation->public_id, $this->store()->state($request, $bot)['current']);

        // The fallback is written into the session, which then takes precedence
        // even if the cookie later disagrees.
        $stale = $this->request(['ai_chat_bot_current' => 'some-other-id']);
        $this->assertSame($conversation->public_id, $this->store()->state($stale, $bot)['current']);
    }

    public function test_state_discards_malformed_history_entries(): void
    {
        $bot = $this->makeBot();
        $request = $this->request();

        $request->session()->put('ai_chat_bot_conversations_' . $bot->id, [
            'current' => 42,
            'history' => [
                ['handle' => 'good', 'public_id' => 'abc'],
                ['handle' => 'missing-public-id'],
                'not-an-array',
            ],
        ]);

        $state = $this->store()->state($request, $bot);

        $this->assertNull($state['current'], 'A non-string current id is discarded.');
        $this->assertSame([['handle' => 'good', 'public_id' => 'abc']], $state['history']);
    }

    public function test_put_caps_history_and_never_writes_it_to_a_cookie(): void
    {
        $bot = $this->makeBot();
        $request = $this->request();

        $history = [];
        for ($i = 0; $i < 40; $i++) {
            $history[] = ['handle' => (string) Str::ulid(), 'public_id' => 'conversation-' . $i];
        }

        $this->store()->put($request, $bot, ['current' => 'conversation-0', 'history' => $history]);

        $stored = $this->store()->state($request, $bot);

        $this->assertCount(25, $stored['history']);
        $this->assertSame('conversation-0', $stored['history'][0]['public_id']);

        $cookie = $this->queuedCookie('ai_chat_bot_current');
        $this->assertSame('conversation-0', $cookie->getValue());
        $this->assertStringNotContainsString('conversation-30', (string) $cookie->getValue());
    }

    public function test_the_current_cookie_carries_the_expected_flags(): void
    {
        $bot = $this->makeBot();

        $this->store()->put($this->request(), $bot, ['current' => 'abc', 'history' => []]);

        $cookie = $this->queuedCookie('ai_chat_bot_current');

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertFalse($cookie->isSecure(), 'An insecure request must not set a secure cookie.');

        // 180 days, allowing a second of clock drift during the assertion.
        $this->assertEqualsWithDelta(
            now()->addMinutes(60 * 24 * 180)->getTimestamp(),
            $cookie->getExpiresTime(),
            5,
        );
    }

    public function test_the_current_cookie_is_secure_on_a_secure_request(): void
    {
        $bot = $this->makeBot();

        $this->store()->put($this->request(secure: true), $bot, ['current' => 'abc', 'history' => []]);

        $this->assertTrue($this->queuedCookie('ai_chat_bot_current')->isSecure());
    }

    public function test_clearing_the_current_conversation_forgets_the_cookie(): void
    {
        $bot = $this->makeBot();
        $request = $this->request();

        $this->store()->put($request, $bot, ['current' => null, 'history' => []]);

        $cookie = $this->queuedCookie('ai_chat_bot_current');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->getExpiresTime() < time());
    }

    public function test_remember_prepends_once_and_makes_the_conversation_current(): void
    {
        $bot = $this->makeBot();
        $conversation = $this->makeConversation($bot);
        $request = $this->request();

        $this->store()->remember($request, $bot, $conversation);
        $this->store()->remember($request, $bot, $conversation);

        $state = $this->store()->state($request, $bot);

        $this->assertCount(1, $state['history'], 'Remembering twice must not duplicate the entry.');
        $this->assertSame($conversation->public_id, $state['current']);
    }

    public function test_start_new_chat_clears_current_but_keeps_history(): void
    {
        $bot = $this->makeBot();
        $conversation = $this->makeConversation($bot);
        $request = $this->request();

        $this->store()->remember($request, $bot, $conversation);
        $this->store()->startNewChat($request, $bot);

        $state = $this->store()->state($request, $bot);

        $this->assertNull($state['current']);
        $this->assertCount(1, $state['history']);
    }

    public function test_switch_to_selects_a_known_handle_and_rejects_an_unknown_one(): void
    {
        $bot = $this->makeBot();
        $first = $this->makeConversation($bot);
        $second = $this->makeConversation($bot);
        $request = $this->request();

        $this->store()->remember($request, $bot, $first);
        $this->store()->remember($request, $bot, $second);

        $handleOfFirst = collect($this->store()->state($request, $bot)['history'])
            ->firstWhere('public_id', $first->public_id)['handle'];

        $this->assertTrue($this->store()->switchTo($request, $bot, $handleOfFirst));
        $this->assertSame($first->public_id, $this->store()->state($request, $bot)['current']);

        $this->assertFalse($this->store()->switchTo($request, $bot, 'not-a-real-handle'));
        $this->assertSame(
            $first->public_id,
            $this->store()->state($request, $bot)['current'],
            'A rejected switch must leave the current conversation alone.',
        );
    }

    public function test_a_conversation_owned_by_another_user_is_discarded(): void
    {
        $bot = $this->makeBot();
        $conversation = $this->makeConversation($bot, userId: 99);

        $request = $this->request();
        $this->store()->remember($request, $bot, $conversation);

        $viewer = new User();
        $viewer->id = 1;
        $viewer->exists = true;
        $request->setUserResolver(fn () => $viewer);

        $this->assertNull($this->store()->currentConversation($request, $bot));
        $this->assertNull(
            $this->store()->state($request, $bot)['current'],
            'The per-bot session key is forgotten on an ownership mismatch.',
        );
    }

    public function test_a_conversation_from_another_bot_is_not_returned(): void
    {
        $alpha = $this->makeBot('alpha');
        $beta = $this->makeBot('beta');
        $conversation = $this->makeConversation($alpha);

        $request = $this->request(['ai_chat_bot_current' => $conversation->public_id]);

        $this->assertNotNull($this->store()->currentConversation($request, $alpha));
        $this->assertNull($this->store()->currentConversation($request, $beta));
    }

    public function test_a_deleted_conversation_is_forgotten(): void
    {
        $bot = $this->makeBot();
        $conversation = $this->makeConversation($bot);
        $request = $this->request();

        $this->store()->remember($request, $bot, $conversation);
        $conversation->forceDelete();

        $this->assertNull($this->store()->currentConversation($request, $bot));
        $this->assertNull($this->store()->state($request, $bot)['current']);
    }
}

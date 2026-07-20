<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiChatBotConversationService;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Symfony\Component\HttpFoundation\Cookie;

class ChatBotCookieTest extends TestCase
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

    private function makeBot(string $slug): AiChatBot
    {
        $system = AiSystem::create([
            'name' => 'Test System ' . $slug,
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

    /**
     * @return array<string, Cookie>
     */
    private function responseCookies(\Illuminate\Testing\TestResponse $response): array
    {
        $cookies = [];

        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }

        return $cookies;
    }

    public function test_show_forgets_legacy_per_bot_cookies_and_never_sets_one(): void
    {
        $bot = $this->makeBot('alpha');

        $response = $this
            ->withUnencryptedCookie('ai_chat_bot_conversations_5', 'stale-value')
            ->withUnencryptedCookie('ai_chat_bot_conversations_16', 'another-stale')
            ->get('/chat/' . $bot->slug, ['X-Inertia' => 'true']);

        $response->assertOk();

        $cookies = $this->responseCookies($response);

        $this->assertArrayHasKey('ai_chat_bot_conversations_5', $cookies);
        $this->assertArrayHasKey('ai_chat_bot_conversations_16', $cookies);
        $this->assertTrue(
            $cookies['ai_chat_bot_conversations_5']->getExpiresTime() < time(),
            'Legacy per-bot cookie should be expired (forgotten).',
        );
        $this->assertTrue(
            $cookies['ai_chat_bot_conversations_16']->getExpiresTime() < time(),
            'Legacy per-bot cookie should be expired (forgotten).',
        );

        foreach ($cookies as $name => $cookie) {
            if (preg_match('/^ai_chat_bot_conversations_\d+$/', $name) === 1) {
                $this->assertTrue(
                    $cookie->getExpiresTime() < time(),
                    "No per-bot cookie should be set with a future expiry: {$name}",
                );
            }
        }
    }

    public function test_current_cookie_resumes_conversation_for_matching_bot_only(): void
    {
        $alpha = $this->makeBot('alpha');
        $beta = $this->makeBot('beta');

        $conversation = $this->app->make(AiChatBotConversationService::class)
            ->startConversation($alpha);

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hello there',
        ]);

        // Matching bot restores the conversation's messages.
        $this
            ->withCookie('ai_chat_bot_current', $conversation->public_id)
            ->get('/chat/' . $alpha->slug, ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertJsonPath('component', 'ai/ChatBot')
            ->assertJsonCount(1, 'props.messages');

        // A different bot gets a fresh chat, not another bot's conversation.
        $this
            ->withCookie('ai_chat_bot_current', $conversation->public_id)
            ->get('/chat/' . $beta->slug, ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertJsonPath('component', 'ai/ChatBot')
            ->assertJsonCount(0, 'props.messages');
    }
}

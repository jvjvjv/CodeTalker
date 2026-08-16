<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\Conversation\CodeTalkerConversationStore;
use Jvjvjv\CodeTalker\Services\LaravelAi\CodeTalkerAgent;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;

class CodeTalkerConversationStoreTest extends TestCase
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

    private function store(): CodeTalkerConversationStore
    {
        return $this->app->make(CodeTalkerConversationStore::class);
    }

    private int $botCount = 0;

    private function makeConversation(): AiConversation
    {
        $system = AiSystem::create([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);

        // Slugs are unique, and some tests need more than one conversation.
        $bot = AiChatBot::create([
            'ai_system_id' => $system->id,
            'name' => 'Test Bot',
            'slug' => 'test-bot-' . ++$this->botCount,
            'prompt_template' => 'You are {{bot_name}}.',
            'is_active' => true,
        ]);

        return AiConversation::create([
            'ai_system_id' => $system->id,
            'ai_chat_bot_id' => $bot->id,
            'feature' => 'chat-bot:test-bot',
        ]);
    }

    private function message(AiConversation $conversation, array $attributes): AiConversationMessage
    {
        return AiConversationMessage::create(array_merge([
            'ai_conversation_id' => $conversation->id,
        ], $attributes));
    }

    /**
     * The whole point of the change: an agent must resolve history from this
     * package's tables, not laravel/ai's own, which it never writes to.
     */
    public function test_the_package_store_replaces_the_framework_default(): void
    {
        $this->assertInstanceOf(
            CodeTalkerConversationStore::class,
            $this->app->make(ConversationStore::class),
        );
    }

    public function test_history_is_returned_oldest_first(): void
    {
        $conversation = $this->makeConversation();

        $this->message($conversation, ['role' => 'user', 'content' => 'First']);
        $this->message($conversation, ['role' => 'assistant', 'content' => 'Second']);
        $this->message($conversation, ['role' => 'user', 'content' => 'Third']);

        $messages = $this->store()->getLatestConversationMessages((string) $conversation->id, 100);

        $this->assertSame(
            ['First', 'Second', 'Third'],
            $messages->map(fn ($message) => $message->content)->all(),
        );
    }

    public function test_the_limit_keeps_the_most_recent_messages(): void
    {
        $conversation = $this->makeConversation();

        foreach (['First', 'Second', 'Third'] as $content) {
            $this->message($conversation, ['role' => 'user', 'content' => $content]);
        }

        $messages = $this->store()->getLatestConversationMessages((string) $conversation->id, 2);

        $this->assertSame(
            ['Second', 'Third'],
            $messages->map(fn ($message) => $message->content)->all(),
        );
    }

    /**
     * The system prompt reaches the agent as instructions. Replaying it as a
     * turn as well would send it twice.
     */
    public function test_system_messages_are_excluded_from_history(): void
    {
        $conversation = $this->makeConversation();

        $this->message($conversation, ['role' => 'system', 'content' => 'You are a bot.']);
        $this->message($conversation, ['role' => 'user', 'content' => 'Hello']);

        $messages = $this->store()->getLatestConversationMessages((string) $conversation->id, 100);

        $this->assertCount(1, $messages);
        $this->assertSame('Hello', $messages->first()->content);
    }

    /**
     * A turn cut off mid-reasoning can leave an assistant row with no text.
     * Providers reject an empty assistant message.
     */
    public function test_an_empty_assistant_turn_is_skipped(): void
    {
        $conversation = $this->makeConversation();

        $this->message($conversation, ['role' => 'user', 'content' => 'Hello']);
        $this->message($conversation, ['role' => 'assistant', 'content' => '']);

        $messages = $this->store()->getLatestConversationMessages((string) $conversation->id, 100);

        $this->assertCount(1, $messages);
    }

    public function test_a_tool_using_turn_replays_as_a_call_and_a_result(): void
    {
        $conversation = $this->makeConversation();

        $this->message($conversation, [
            'role' => 'assistant',
            'content' => 'I looked it up.',
            'tool_calls' => [[
                'id' => 'call_1',
                'name' => 'fetch-web-page',
                'arguments' => ['url' => 'https://example.test'],
            ]],
            'tool_results' => [[
                'id' => 'call_1',
                'name' => 'fetch-web-page',
                'arguments' => ['url' => 'https://example.test'],
                'result' => 'Page content',
            ]],
        ]);

        $messages = $this->store()->getLatestConversationMessages((string) $conversation->id, 100);

        $this->assertCount(3, $messages);
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[1]);
        // The text the model produced alongside the call follows the result.
        $this->assertInstanceOf(AssistantMessage::class, $messages[2]);
        $this->assertSame('I looked it up.', $messages[2]->content);
    }

    /**
     * Tool calls with no recorded results cannot be replayed as a pair — a
     * dangling call would leave the provider waiting for a result.
     */
    public function test_a_tool_call_with_no_result_falls_back_to_text(): void
    {
        $conversation = $this->makeConversation();

        $this->message($conversation, [
            'role' => 'assistant',
            'content' => 'Partial answer.',
            'tool_calls' => [['id' => 'call_1', 'name' => 'search-web', 'arguments' => []]],
        ]);

        $messages = $this->store()->getLatestConversationMessages((string) $conversation->id, 100);

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
    }

    /**
     * This is what the transcript builder could not do, and why attachment
     * replay was previously impossible.
     */
    public function test_a_user_turn_replays_its_attachments(): void
    {
        $conversation = $this->makeConversation();

        $this->message($conversation, [
            'role' => 'user',
            'content' => 'What is in this image?',
            'attachments' => [[
                'type' => 'base64-image',
                'base64' => base64_encode('not-really-an-image'),
                'mime' => 'image/png',
            ]],
        ]);

        $messages = $this->store()->getLatestConversationMessages((string) $conversation->id, 100);

        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertCount(1, $messages[0]->attachments);
    }

    /**
     * A Code Talker conversation needs an AiSystem, which the contract has no
     * way to supply. Failing with an explanation beats failing on a NOT NULL
     * constraint deep inside the framework's remembering middleware.
     */
    public function test_creating_a_conversation_through_the_contract_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/require an AiSystem/');

        $this->store()->storeConversation(null, 'A stored conversation');
    }

    public function test_the_latest_conversation_is_found_per_user(): void
    {
        $older = $this->makeConversation();
        $newer = $this->makeConversation();

        $older->forceFill(['user_id' => 7])->save();
        $newer->forceFill(['user_id' => 7])->save();

        AiConversation::whereKey($older->id)->update(['updated_at' => now()->subDay()]);

        $this->assertSame((string) $newer->id, $this->store()->latestConversationId(7));
        $this->assertNull($this->store()->latestConversationId(999));
    }

    // ------------------------------------------------------------------ agent

    public function test_stored_history_precedes_messages_appended_within_the_turn(): void
    {
        $conversation = $this->makeConversation();

        $this->message($conversation, ['role' => 'user', 'content' => 'Stored turn']);

        $agent = (new CodeTalkerAgent('test-provider'))
            ->withStoredConversation((string) $conversation->id);

        $agent->append(new AssistantMessage('Appended turn'));

        $this->assertSame(
            ['Stored turn', 'Appended turn'],
            collect($agent->messages())->map(fn ($message) => $message->content)->all(),
        );
    }

    /**
     * The package reads history but keeps writing through TurnRecorder, which
     * also persists partial turns. Attaching a participant would arm laravel/ai's
     * remembering middleware and persist every turn a second time.
     */
    public function test_replaying_history_does_not_attach_a_conversation_participant(): void
    {
        $agent = (new CodeTalkerAgent('test-provider'))->withStoredConversation('1');

        $this->assertFalse($agent->hasConversationParticipant());
        $this->assertNull($agent->conversationParticipant());
        $this->assertSame('1', $agent->currentConversation());
    }

    public function test_an_agent_with_no_conversation_returns_only_appended_messages(): void
    {
        $agent = new CodeTalkerAgent('test-provider');

        $agent->append(new AssistantMessage('Only this'));

        $this->assertSame(
            ['Only this'],
            collect($agent->messages())->map(fn ($message) => $message->content)->all(),
        );
    }
}

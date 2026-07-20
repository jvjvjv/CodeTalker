<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Jobs\ProcessAiMemoryJob;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiInteractionLog;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiChatBotConversationService;
use Jvjvjv\CodeTalker\Services\LaravelAi\CodeTalkerAgent;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Laravel\Ai\Responses\Data\ToolCall;

class AiChatBotConversationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('code-talker.user_model', \Illuminate\Foundation\Auth\User::class);
    }

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

    private function makeBot(array $systemAttributes = [], array $botAttributes = []): AiChatBot
    {
        $system = AiSystem::create(array_merge([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ], $systemAttributes));

        return AiChatBot::create(array_merge([
            'ai_system_id' => $system->id,
            'name' => 'Test Bot',
            'slug' => 'test-bot',
            'prompt_template' => 'You are {{bot_name}}.',
            'is_active' => true,
        ], $botAttributes));
    }

    /**
     * @return array<int, array<string, mixed>> decoded JSON events, excluding [DONE]
     */
    private function drainAndDecode(iterable $stream, array &$rawLines = []): array
    {
        $events = [];

        foreach ($stream as $line) {
            $rawLines[] = $line;

            $payload = trim(str_replace('data: ', '', $line));

            if ($payload === '[DONE]') {
                continue;
            }

            $events[] = json_decode($payload, true);
        }

        return $events;
    }

    public function test_streams_a_text_turn_in_the_legacy_wire_format_and_persists_everything(): void
    {
        Queue::fake();
        CodeTalkerAgent::fake(['Hello world from fake']);

        $bot = $this->makeBot();
        $service = $this->app->make(AiChatBotConversationService::class);
        $conversation = $service->startConversation($bot);

        $rawLines = [];
        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Hi there'), $rawLines);

        $this->assertSame("data: [DONE]\n\n", end($rawLines));

        $types = array_column($events, 'type');

        $this->assertSame('status', $types[0]);
        $this->assertSame(1, array_count_values($types)['message_start']);
        $this->assertContains('content_block_delta', $types);
        $this->assertSame(['message_delta', 'message_stop'], array_slice($types, -2));

        $text = collect($events)
            ->where('type', 'content_block_delta')
            ->pluck('delta.text')
            ->implode('');

        $this->assertSame('Hello world from fake', $text);

        $messageDelta = collect($events)->firstWhere('type', 'message_delta');
        $this->assertSame('end_turn', $messageDelta['delta']['stop_reason']);

        $assistantMessage = AiConversationMessage::where('role', 'assistant')->first();
        $this->assertNotNull($assistantMessage);
        $this->assertSame('Hello world from fake', $assistantMessage->content);

        $this->assertSame(
            ['request', 'response'],
            AiLlmMessage::orderBy('id')->pluck('direction')->all(),
        );

        $request = AiLlmMessage::where('direction', 'request')->first();
        $this->assertSame('1', $request->turn_number);
        $this->assertSame('claude-sonnet-4-6', $request->request_data['model']);
        $requestMessages = $request->request_data['messages'];
        $this->assertSame('Hi there', end($requestMessages)['content']);

        $response = AiLlmMessage::where('direction', 'response')->first();
        $this->assertSame('end_turn', $response->response_data['stop_reason']);
        $this->assertNotEmpty($response->response_data['events']);

        $log = AiInteractionLog::first();
        $this->assertSame('success', $log->status->value);

        $conversationTitle = $conversation->fresh()->title;
        $this->assertSame('Hi there', $conversationTitle);

        // Memory extraction is not per-turn. It fires once, when
        // ai:complete-idle-conversations marks the conversation Completed.
        Queue::assertNotPushed(ProcessAiMemoryJob::class);
    }

    public function test_tool_calls_run_through_the_registry_and_are_logged(): void
    {
        Queue::fake();
        Http::fake([
            'https://example.com/page' => Http::response(
                '<html><head><title>Hi</title></head><body><p>Body text.</p></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        CodeTalkerAgent::fake([
            new ToolCall('tool-1', 'fetch-web-page', ['url' => 'https://example.com/page']),
            'Summary after reading the page',
        ]);

        $bot = $this->makeBot(
            ['allowed_tools' => ['fetch-web-page']],
            ['tools_enabled' => true],
        );

        $service = $this->app->make(AiChatBotConversationService::class);
        $conversation = $service->startConversation($bot);

        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Read the page'));

        $types = array_column($events, 'type');

        // Tool activity is never forwarded to the browser.
        $this->assertNotContains('tool_call', $types);
        $this->assertSame(1, array_count_values($types)['message_start']);

        $text = collect($events)
            ->where('type', 'content_block_delta')
            ->pluck('delta.text')
            ->implode('');

        $this->assertSame('Summary after reading the page', $text);

        $response = AiLlmMessage::where('direction', 'response')->first();

        $this->assertSame(
            [['id' => 'tool-1', 'name' => 'fetch-web-page']],
            $response->response_data['tool_calls'],
        );

        $eventTypes = array_column($response->response_data['events'], 'type');
        $this->assertContains('tool_call', $eventTypes);
        $this->assertContains('tool_result', $eventTypes);
    }

    public function test_provider_failures_emit_the_legacy_error_event_and_log_the_failure(): void
    {
        Queue::fake();

        $bot = $this->makeBot(['provider' => 'anthropic']);
        $bot->aiSystem->forceFill(['provider' => 'not-a-real-provider'])->save();

        $service = $this->app->make(AiChatBotConversationService::class);
        $conversation = $service->startConversation($bot);

        $rawLines = [];
        $events = $this->drainAndDecode($service->continueConversation($conversation->fresh(), 'Hi'), $rawLines);

        $error = collect($events)->firstWhere('type', 'error');
        $this->assertNotNull($error);
        $this->assertStringContainsString('Unsupported AI provider', $error['message']);

        $log = AiInteractionLog::first();
        $this->assertSame('error', $log->status->value);
        $this->assertStringContainsString('Unsupported AI provider', $log->error_message);

        $request = AiLlmMessage::where('direction', 'request')->first();
        $this->assertArrayHasKey('error', $request->request_data);

        Queue::assertNotPushed(ProcessAiMemoryJob::class);
    }
}

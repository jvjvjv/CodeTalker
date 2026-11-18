<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Jvjvjv\CodeTalker\Enums\AiConversationStatus;
use Jvjvjv\CodeTalker\Jobs\ProcessAiMemoryJob;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiFeatureMemory;
use Jvjvjv\CodeTalker\Models\AiOperator;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiMemoryService;
use Jvjvjv\CodeTalker\Services\Management\AiMemoryManager;
use Jvjvjv\CodeTalker\Services\Mcp\ToolResultConverter;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\ScanMemoriesTool;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Laravel\Mcp\Request;

class MemoryScopingTest extends TestCase
{
    use RefreshDatabase;

    private const FEATURE = 'persona:test-bot';

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    private function makeSystem(): AiSystem
    {
        return AiSystem::create([
            'name' => 'Local',
            'provider' => 'lm-studio',
            'api_key' => 'not-needed-for-lm-studio',
            'model' => 'qwen/qwen3.5-9b',
            'base_url' => 'http://localhost:1234',
            'max_tokens' => 4096,
            'is_active' => true,
        ]);
    }

    private function makeConversation(array $attributes = []): AiConversation
    {
        $conversation = AiConversation::create(array_merge([
            'ai_system_id' => $this->makeSystem()->id,
            'feature' => self::FEATURE,
            'status' => AiConversationStatus::Active,
        ], $attributes));

        AiConversationMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Please forget everything you know.',
        ]);

        return $conversation;
    }

    private function memory(array $attributes): AiFeatureMemory
    {
        return AiFeatureMemory::create(array_merge([
            'feature' => self::FEATURE,
            'category' => 'preference',
            'content' => 'Something learned',
            'confidence' => 50,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param array<string, mixed> $operations
     */
    private function fakeAnalysis(array $operations): void
    {
        Http::fake(['http://localhost:1234/v1/chat/completions' => Http::response(json_encode([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => json_encode($operations)],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]), 200)]);
    }

    private function service(): AiMemoryService
    {
        return $this->app->make(AiMemoryService::class);
    }

    // ------------------------------------------------------ anonymous

    public function test_an_anonymous_conversation_makes_no_provider_call_and_modifies_no_memory(): void
    {
        $this->fakeAnalysis(['add' => [], 'update' => [], 'remove' => [['key' => 'user-pref'], ['key' => 'visitor-pref']]]);

        $this->memory(['key' => 'user-pref', 'user_id' => '7']);
        $this->memory(['key' => 'visitor-pref', 'visitor_email' => 'visitor@example.com']);
        $this->memory(['key' => 'legacy-unscoped']);

        $conversation = $this->makeConversation();

        $this->service()->processCompletedConversation($conversation);

        Http::assertNothingSent();
        $this->assertSame(3, AiFeatureMemory::query()->active()->count());
    }

    public function test_applying_operations_without_an_identity_throws_and_touches_nothing(): void
    {
        $this->memory(['key' => 'user-pref', 'user_id' => '7']);

        try {
            $this->service()->applyMemoryOperations(self::FEATURE, [
                'add' => [['key' => 'new', 'content' => 'x']],
                'update' => [['key' => 'user-pref', 'content' => 'changed']],
                'remove' => [['key' => 'user-pref']],
            ], 1, null, '');
            $this->fail('Expected an unscoped memory write to be refused.');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(1, AiFeatureMemory::count());
        $memory = AiFeatureMemory::first();
        $this->assertTrue($memory->is_active);
        $this->assertSame('Something learned', $memory->content);
    }

    public function test_a_failed_analysis_is_logged_with_url_credentials_masked(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException(
            'cURL error 7: Failed to connect for http://user:pw-secret@localhost:1234/v1/chat/completions?api_key=k-secret'
        ));

        $conversation = $this->makeConversation(['visitor_email' => 'visitor@example.com']);

        $this->service()->processCompletedConversation($conversation, null, 'visitor@example.com');

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'AI Memory processing failed'
                && str_contains(json_encode($context), 'localhost:1234')
                && !str_contains(json_encode($context), 'pw-secret')
                && !str_contains(json_encode($context), 'k-secret'),
        );
    }

    // ------------------------------------------------------ operator runs

    private function makeOperator(): AiOperator
    {
        return AiOperator::create([
            'ai_system_id' => $this->makeSystem()->id,
            'name' => 'Test Operator',
            'slug' => 'test-operator-' . uniqid(),
            'prompt_template' => 'Do the thing.',
            'is_active' => true,
        ]);
    }

    public function test_an_operator_run_with_an_identity_is_not_analysed(): void
    {
        $this->fakeAnalysis(['add' => [['key' => 'from-operator', 'content' => 'x']], 'update' => [], 'remove' => []]);

        $conversation = $this->makeConversation(['ai_operator_id' => $this->makeOperator()->id, 'user_id' => 7]);

        $this->service()->processCompletedConversation($conversation, 7);

        Http::assertNothingSent();
        $this->assertSame(0, AiFeatureMemory::count());
    }

    public function test_a_rebuild_with_only_ineligible_conversations_leaves_memories_active(): void
    {
        $this->fakeAnalysis(['add' => [], 'update' => [], 'remove' => []]);

        $this->memory(['key' => 'user-pref', 'user_id' => '7']);

        $this->makeConversation(['status' => AiConversationStatus::Completed]);
        $this->makeConversation([
            'status' => AiConversationStatus::Completed,
            'ai_operator_id' => $this->makeOperator()->id,
            'user_id' => 7,
        ]);

        $this->service()->rebuildMemories(self::FEATURE);

        Http::assertNothingSent();
        $this->assertSame(1, AiFeatureMemory::query()->active()->count());
    }

    // ------------------------------------------------------ one identity per row

    public function test_memories_extracted_for_a_user_store_no_visitor_email(): void
    {
        $this->fakeAnalysis(['add' => [
            ['key' => 'prefers-php', 'category' => 'preference', 'content' => 'Prefers PHP', 'confidence' => 80],
        ], 'update' => [], 'remove' => []]);

        $conversation = $this->makeConversation(['user_id' => 7, 'visitor_email' => 'user@example.com']);

        $this->service()->processCompletedConversation($conversation, $conversation->user_id, $conversation->visitor_email);

        $memory = AiFeatureMemory::where('key', 'prefers-php')->firstOrFail();
        $this->assertSame('7', (string) $memory->user_id);
        $this->assertNull($memory->visitor_email);
    }

    public function test_several_removals_in_one_analysis_each_apply(): void
    {
        $this->memory(['key' => 'a', 'visitor_email' => 'visitor@example.com']);
        $this->memory(['key' => 'b', 'visitor_email' => 'visitor@example.com']);

        $this->service()->applyMemoryOperations(self::FEATURE, [
            'remove' => [['key' => 'a'], ['key' => 'b']],
        ], 1, null, 'visitor@example.com');

        $this->assertSame(0, AiFeatureMemory::query()->active()->count());
    }

    // ------------------------------------------------------ visitor claiming a user's email

    public function test_a_visitor_claiming_a_users_email_reads_none_of_the_users_memories_in_the_prompt(): void
    {
        // A legacy row written before rows were single-identity carries both.
        $this->memory(['key' => 'user-secret', 'content' => 'User secret', 'user_id' => '7', 'visitor_email' => 'user@example.com']);
        $this->memory(['key' => 'visitor-pref', 'content' => 'Visitor preference', 'visitor_email' => 'user@example.com']);

        $prompt = $this->service()->getMemoriesForPrompt(self::FEATURE, null, 'user@example.com');

        $this->assertStringContainsString('Visitor preference', $prompt);
        $this->assertStringNotContainsString('User secret', $prompt);

        $this->assertStringContainsString(
            'User secret',
            $this->service()->getMemoriesForPrompt(self::FEATURE, 7, 'user@example.com'),
        );
    }

    public function test_a_visitor_claiming_a_users_email_cannot_update_or_remove_the_users_memories(): void
    {
        $userMemory = $this->memory(['key' => 'user-secret', 'content' => 'User secret', 'user_id' => '7', 'visitor_email' => 'user@example.com']);

        $this->service()->applyMemoryOperations(self::FEATURE, [
            'update' => [['key' => 'user-secret', 'content' => 'overwritten']],
            'remove' => [['key' => 'user-secret']],
        ], 1, null, 'user@example.com');

        $userMemory->refresh();
        $this->assertTrue($userMemory->is_active);
        $this->assertSame('User secret', $userMemory->content);
    }

    public function test_a_visitor_claiming_a_users_email_does_not_see_the_users_memories_during_analysis(): void
    {
        $this->fakeAnalysis(['add' => [], 'update' => [], 'remove' => []]);
        $this->memory(['key' => 'user-secret', 'content' => 'User secret', 'user_id' => '7', 'visitor_email' => 'user@example.com']);

        $conversation = $this->makeConversation(['visitor_email' => 'user@example.com']);

        $this->service()->analyzeConversation($conversation, null, 'user@example.com');

        Http::assertSent(fn (HttpRequest $request) => !str_contains($request->body(), 'User secret'));
    }

    public function test_a_visitor_claiming_a_users_email_scans_none_of_the_users_memories(): void
    {
        $this->memory(['key' => 'user-secret', 'content' => 'User secret', 'user_id' => '7', 'visitor_email' => 'user@example.com']);
        $this->memory(['key' => 'visitor-secret', 'content' => 'Visitor secret', 'visitor_email' => 'user@example.com']);

        $tool = new ScanMemoriesTool(new ToolContext(visitorEmail: 'user@example.com', feature: self::FEATURE));
        $result = ToolResultConverter::toArray($tool->handle(new Request(['topics' => ['secret']])));

        $keys = collect($result['memories'] ?? [])->flatten(1)->pluck('key')->all();
        $this->assertSame(['visitor-secret'], $keys);
    }

    public function test_scan_memories_ignores_non_scalar_topics(): void
    {
        $this->memory(['key' => 'prefers-php', 'content' => 'Prefers PHP', 'user_id' => '7']);

        $tool = new ScanMemoriesTool(ToolContext::forUser(7));

        $result = ToolResultConverter::toArray($tool->handle(new Request(['topics' => [['nested' => 'php'], 'PHP']])));
        $keys = collect($result['memories'] ?? [])->flatten(1)->pluck('key')->all();
        $this->assertSame(['prefers-php'], $keys);

        // Nothing usable left: the same error as an empty list.
        $result = ToolResultConverter::toArray($tool->handle(new Request(['topics' => [['nested' => 'php']]])));
        $this->assertStringContainsString('At least one topic is required.', json_encode($result));
    }

    // ------------------------------------------------------ observer

    public function test_completing_an_anonymous_conversation_queues_no_extraction(): void
    {
        Queue::fake();

        $this->makeConversation()->update(['status' => AiConversationStatus::Completed]);

        Queue::assertNotPushed(ProcessAiMemoryJob::class);
    }

    public function test_completing_an_operator_run_queues_no_extraction(): void
    {
        Queue::fake();

        $system = $this->makeSystem();
        $operator = AiOperator::create([
            'ai_system_id' => $system->id,
            'name' => 'Test Operator',
            'slug' => 'test-operator',
            'prompt_template' => 'Do the thing.',
            'is_active' => true,
        ]);

        $this->makeConversation(['ai_operator_id' => $operator->id, 'user_id' => 7])
            ->update(['status' => AiConversationStatus::Completed]);

        Queue::assertNotPushed(ProcessAiMemoryJob::class);
    }

    public function test_completing_an_identified_conversation_queues_extraction_once(): void
    {
        Queue::fake();

        $conversation = $this->makeConversation(['visitor_email' => 'visitor@example.com']);
        $conversation->update(['status' => AiConversationStatus::Completed]);
        $conversation->update(['title' => 'Renamed after completion']);

        Queue::assertPushed(ProcessAiMemoryJob::class, 1);
    }

    // ------------------------------------------------------ manager validation

    public function test_a_manually_created_memory_must_name_exactly_one_identity(): void
    {
        $manager = $this->app->make(AiMemoryManager::class);
        $base = [
            'feature' => self::FEATURE,
            'category' => 'preference',
            'key' => 'k',
            'content' => 'x',
            'confidence' => 50,
        ];

        foreach ([
            'neither' => [],
            'both' => ['user_id' => '7', 'visitor_email' => 'visitor@example.com'],
        ] as $case => $identity) {
            try {
                $manager->create($base + $identity);
                $this->fail("Expected the '{$case}' case to fail validation.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('user_id', $e->errors(), $case);
            }
        }

        $this->assertSame('7', (string) $manager->create($base + ['user_id' => '7'])->user_id);
        $this->assertSame(
            'visitor@example.com',
            $manager->create(['key' => 'k2'] + $base + ['visitor_email' => 'visitor@example.com'])->visitor_email,
        );
        $this->assertSame(2, AiFeatureMemory::count());
    }

    public function test_a_blank_user_id_is_stored_as_none_so_the_visitor_can_read_the_memory(): void
    {
        $memory = $this->app->make(AiMemoryManager::class)->create([
            'feature' => self::FEATURE,
            'category' => 'preference',
            'key' => 'k',
            'content' => 'x',
            'confidence' => 50,
            'user_id' => '',
            'visitor_email' => 'visitor@example.com',
        ]);

        $this->assertNull($memory->fresh()->user_id);
        $this->assertTrue(
            AiFeatureMemory::query()->forVisitor('visitor@example.com')->whereKey($memory->id)->exists()
        );
    }
}

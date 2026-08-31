<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Enums\AiInteractionStatus;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiInteractionLog;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\ConversationUsageService;
use Jvjvjv\CodeTalker\Tests\TestCase;

class ConversationUsageServiceTest extends TestCase
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

    private function conversation(): AiConversation
    {
        $system = AiSystem::create([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);

        return AiConversation::create([
            'ai_system_id' => $system->id,
            'feature' => 'chat-bot:test',
        ]);
    }

    private function log(AiConversation $conversation, AiInteractionStatus $status, int $in, int $out): void
    {
        AiInteractionLog::create([
            'ai_system_id' => $conversation->ai_system_id,
            'ai_conversation_id' => $conversation->id,
            'feature' => $conversation->feature,
            'model' => 'claude-sonnet-4-6',
            'input_tokens' => $in,
            'output_tokens' => $out,
            'status' => $status,
        ]);
    }

    public function test_aborted_turns_still_count_towards_conversation_usage(): void
    {
        $conversation = $this->conversation();

        $this->log($conversation, AiInteractionStatus::Success, 100, 20);
        // The browser hanging up mid-stream does not refund the tokens the
        // provider had already generated, so an aborted turn is still billed.
        $this->log($conversation, AiInteractionStatus::Aborted, 300, 40);
        // A turn that never reached the provider is not.
        $this->log($conversation, AiInteractionStatus::Error, 999, 999);

        $usage = $this->app->make(ConversationUsageService::class)->buildUsageSummary($conversation);

        $this->assertSame(400, $usage['input_tokens']);
        $this->assertSame(60, $usage['output_tokens']);
        $this->assertSame(460, $usage['total_tokens']);
    }
}

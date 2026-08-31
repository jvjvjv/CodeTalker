<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Models\AiTurnEvent;
use Jvjvjv\CodeTalker\Models\AiTurnRun;
use Jvjvjv\CodeTalker\Tests\TestCase;

class AiTurnRunModelTest extends TestCase
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
            'feature' => 'persona:test',
        ]);
    }

    public function test_a_run_gets_a_public_id_and_casts_its_status(): void
    {
        $run = AiTurnRun::create([
            'ai_conversation_id' => $this->conversation()->id,
            'status' => AiTurnRunStatus::Queued,
            'prompt' => 'Hello',
        ]);

        $this->assertNotEmpty($run->public_id);
        $this->assertSame(AiTurnRunStatus::Queued, $run->fresh()->status);
        $this->assertFalse($run->status->isTerminal());
    }

    public function test_terminal_statuses_are_the_ones_a_reader_stops_on(): void
    {
        $this->assertTrue(AiTurnRunStatus::Completed->isTerminal());
        $this->assertTrue(AiTurnRunStatus::Failed->isTerminal());
        $this->assertTrue(AiTurnRunStatus::Cancelled->isTerminal());
        $this->assertTrue(AiTurnRunStatus::Abandoned->isTerminal());
        $this->assertFalse(AiTurnRunStatus::Queued->isTerminal());
        $this->assertFalse(AiTurnRunStatus::Running->isTerminal());
    }

    public function test_events_belong_to_a_run_and_keep_their_payload_shape(): void
    {
        $run = AiTurnRun::create([
            'ai_conversation_id' => $this->conversation()->id,
            'status' => AiTurnRunStatus::Running,
            'prompt' => 'Hello',
        ]);

        AiTurnEvent::create([
            'ai_turn_run_id' => $run->id,
            'sequence' => 1,
            'payload' => ['type' => 'content_block_delta', 'delta' => ['text' => 'Hi']],
        ]);

        $event = $run->events()->first();

        $this->assertSame(1, $event->sequence);
        $this->assertSame('Hi', $event->payload['delta']['text']);
    }

    public function test_a_run_cannot_reuse_a_sequence(): void
    {
        $run = AiTurnRun::create([
            'ai_conversation_id' => $this->conversation()->id,
            'status' => AiTurnRunStatus::Running,
            'prompt' => 'Hello',
        ]);

        AiTurnEvent::create(['ai_turn_run_id' => $run->id, 'sequence' => 1, 'payload' => ['type' => 'a']]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        AiTurnEvent::create(['ai_turn_run_id' => $run->id, 'sequence' => 1, 'payload' => ['type' => 'b']]);
    }
}

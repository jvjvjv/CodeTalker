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

class PruneTurnEventsCommandTest extends TestCase
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

    private function makeRun(AiTurnRunStatus $status, int $daysOld): AiTurnRun
    {
        $system = AiSystem::create([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);

        $conversation = AiConversation::create([
            'ai_system_id' => $system->id,
            'feature' => 'persona:test',
        ]);

        $run = AiTurnRun::create([
            'ai_conversation_id' => $conversation->id,
            'status' => $status,
            'prompt' => 'Hi',
        ]);

        $run->forceFill(['created_at' => now()->subDays($daysOld)])->save();

        AiTurnEvent::create(['ai_turn_run_id' => $run->id, 'sequence' => 1, 'payload' => ['type' => 'a']]);

        return $run;
    }

    public function test_it_removes_old_terminal_runs_and_their_events(): void
    {
        config()->set('code-talker.turns.retention_days', 7);

        $old = $this->makeRun(AiTurnRunStatus::Completed, daysOld: 10);
        $recent = $this->makeRun(AiTurnRunStatus::Completed, daysOld: 1);
        $live = $this->makeRun(AiTurnRunStatus::Running, daysOld: 10);

        $this->artisan('ai:prune-turn-events')->assertExitCode(0);

        $this->assertNull(AiTurnRun::find($old->id));
        $this->assertSame(0, AiTurnEvent::where('ai_turn_run_id', $old->id)->count());

        $this->assertNotNull(AiTurnRun::find($recent->id));

        // A long-running turn is not garbage, however old the row is.
        $this->assertNotNull(AiTurnRun::find($live->id));
    }

    public function test_zero_retention_days_disables_pruning_instead_of_deleting_everything(): void
    {
        config()->set('code-talker.turns.retention_days', 0);

        $old = $this->makeRun(AiTurnRunStatus::Completed, daysOld: 365);

        $this->artisan('ai:prune-turn-events')->assertExitCode(0);

        $this->assertNotNull(AiTurnRun::find($old->id));
        $this->assertSame(1, AiTurnEvent::where('ai_turn_run_id', $old->id)->count());
    }
}

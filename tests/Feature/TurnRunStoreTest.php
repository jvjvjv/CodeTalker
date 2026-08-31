<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\Conversation\TurnRunStore;
use Jvjvjv\CodeTalker\Tests\TestCase;

class TurnRunStoreTest extends TestCase
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

    private function store(): TurnRunStore
    {
        return $this->app->make(TurnRunStore::class);
    }

    public function test_appended_events_are_sequenced_from_one(): void
    {
        $store = $this->store();
        $run = $store->open($this->conversation(), 'Hello');

        $this->assertSame(1, $store->append($run, ['type' => 'message_start']));
        $this->assertSame(2, $store->append($run, ['type' => 'content_block_delta']));

        $this->assertSame(
            ['message_start', 'content_block_delta'],
            $store->eventsAfter($run, 0)->pluck('payload.type')->all(),
        );
    }

    public function test_events_after_a_sequence_returns_only_the_tail(): void
    {
        $store = $this->store();
        $run = $store->open($this->conversation(), 'Hello');

        $store->append($run, ['type' => 'a']);
        $store->append($run, ['type' => 'b']);
        $store->append($run, ['type' => 'c']);

        $this->assertSame(['b', 'c'], $store->eventsAfter($run, 1)->pluck('payload.type')->all());
        $this->assertTrue($store->eventsAfter($run, 3)->isEmpty());
    }

    public function test_a_run_nobody_polls_is_stopped_once_the_grace_period_lapses(): void
    {
        config()->set('code-talker.turns.abandon_after_seconds', 30);

        // A zero-interval store: this test travels through Carbon test time, so
        // the wall-clock throttle cache must be out of the way for the second
        // shouldStop() call to read fresh state.
        $store = new TurnRunStore(0.0);
        $run = $store->open($this->conversation(), 'Hello');

        // Freshly opened and never polled: the reader has not connected yet.
        $this->assertFalse($store->shouldStop($run));

        // Still never polled, but now well past the grace period.
        Carbon::setTestNow(now()->addSeconds(31));
        $this->assertTrue($store->shouldStop($run));
        $this->assertSame(AiTurnRunStatus::Abandoned, $store->stopStatusFor($run));

        Carbon::setTestNow();
    }

    public function test_polling_keeps_a_run_alive(): void
    {
        config()->set('code-talker.turns.abandon_after_seconds', 30);

        $store = $this->store();
        $run = $store->open($this->conversation(), 'Hello');

        Carbon::setTestNow(now()->addSeconds(29));
        $store->touchPoll($run);

        Carbon::setTestNow(now()->addSeconds(20));
        $this->assertFalse($store->shouldStop($run));

        Carbon::setTestNow();
    }

    public function test_an_explicit_cancel_stops_the_run(): void
    {
        $store = $this->store();
        $run = $store->open($this->conversation(), 'Hello');

        $store->requestCancel($run);

        $this->assertTrue($store->shouldStop($run));
        $this->assertSame(AiTurnRunStatus::Cancelled, $store->stopStatusFor($run));
    }

    public function test_should_stop_is_throttled_so_it_never_queries_per_token(): void
    {
        $store = $this->store();
        $run = $store->open($this->conversation(), 'Hello');

        $store->shouldStop($run);

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries): void {
            $queries++;
        });

        for ($i = 0; $i < 50; $i++) {
            $store->shouldStop($run);
        }

        $this->assertSame(0, $queries);
    }

    public function test_finishing_records_the_status_and_the_error(): void
    {
        $store = $this->store();
        $run = $store->open($this->conversation(), 'Hello');

        $store->finish($run, AiTurnRunStatus::Failed, 'provider exploded');

        $run->refresh();
        $this->assertSame(AiTurnRunStatus::Failed, $run->status);
        $this->assertSame('provider exploded', $run->error_message);
        $this->assertNotNull($run->finished_at);
    }
}

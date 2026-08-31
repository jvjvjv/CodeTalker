<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Models\AiTurnEvent;
use Jvjvjv\CodeTalker\Models\AiTurnRun;
use Jvjvjv\CodeTalker\Services\ChatBot\SseFrameEncoder;
use Jvjvjv\CodeTalker\Services\Conversation\TurnEventStream;
use Jvjvjv\CodeTalker\Services\Conversation\TurnRunStore;
use Jvjvjv\CodeTalker\Tests\TestCase;

class TurnEventStreamTest extends TestCase
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

        config()->set('code-talker.turns.poll_interval_ms', 1);
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

    public function test_a_finished_run_replays_from_the_beginning(): void
    {
        $store = $this->app->make(TurnRunStore::class);
        $run = $store->open($this->conversation(), 'Hi');
        $store->markRunning($run);
        $store->append($run, ['type' => 'message_start']);
        $store->append($run, ['type' => 'content_block_delta', 'delta' => ['text' => 'Hi']]);
        $store->finish($run, AiTurnRunStatus::Completed);

        $events = iterator_to_array($this->app->make(TurnEventStream::class)->stream($run, 0), false);

        $this->assertSame(['message_start', 'content_block_delta'], array_column($events, 'type'));
        $this->assertSame([1, 2], array_column($events, '_seq'));
    }

    public function test_a_reload_resumes_from_the_last_sequence_it_saw(): void
    {
        $store = $this->app->make(TurnRunStore::class);
        $run = $store->open($this->conversation(), 'Hi');
        $store->markRunning($run);
        $store->append($run, ['type' => 'a']);
        $store->append($run, ['type' => 'b']);
        $store->append($run, ['type' => 'c']);
        $store->finish($run, AiTurnRunStatus::Completed);

        $events = iterator_to_array($this->app->make(TurnEventStream::class)->stream($run, 1), false);

        $this->assertSame(['b', 'c'], array_column($events, 'type'));
    }

    public function test_the_final_event_survives_a_run_finishing_mid_poll(): void
    {
        $store = $this->app->make(TurnRunStore::class);
        $run = $store->open($this->conversation(), 'Hi');
        $store->markRunning($run);
        $store->append($run, ['type' => 'first']);

        $events = $this->app->make(TurnEventStream::class)->stream($run, 0);

        $events->rewind();
        $this->assertSame('first', $events->current()['type']);

        // The job's last act: append, then mark finished. A reader that read
        // status before events would drop 'last' entirely.
        $store->append($run, ['type' => 'last']);
        $store->finish($run, AiTurnRunStatus::Completed);

        $events->next();
        $this->assertSame('last', $events->current()['type']);

        $events->next();
        $this->assertFalse($events->valid());
    }

    public function test_reading_marks_the_run_as_polled_so_it_is_not_abandoned(): void
    {
        $store = $this->app->make(TurnRunStore::class);
        $run = $store->open($this->conversation(), 'Hi');
        $store->markRunning($run);
        $store->append($run, ['type' => 'a']);
        $store->finish($run, AiTurnRunStatus::Completed);

        iterator_to_array($this->app->make(TurnEventStream::class)->stream($run, 0), false);

        $this->assertNotNull($run->fresh()->last_polled_at);
    }

    /**
     * A single-threaded test cannot interleave an append between the reader's
     * empty read and its status check, so the race the drain exists for is
     * pinned with a scripted store instead: empty on the first read, rows on
     * the next, against a run that is already terminal. Remove the drain and
     * this fails — the reader would return on the empty read and never see
     * the rows the job appended just before finishing.
     */
    public function test_the_terminal_drain_reads_events_that_landed_after_the_empty_read(): void
    {
        $run = $this->terminalRun();

        $stub = $this->scriptedStore([
            new Collection(),
            new Collection([
                $this->event(1, 'mid-a'),
                $this->event(2, 'mid-b'),
            ]),
        ]);

        $events = iterator_to_array((new TurnEventStream($stub))->stream($run, 0), false);

        $this->assertSame(['mid-a', 'mid-b'], array_column($events, 'type'));
        $this->assertSame([1, 2], array_column($events, '_seq'));
    }

    /**
     * eventsAfter() caps each read at 200 rows. The drain must page until a
     * read comes back empty; a drain that reads once would truncate a >200
     * backlog and the encoder would still append [DONE] — a cleanly finished
     * turn silently missing the end of its answer.
     */
    public function test_the_terminal_drain_pages_through_a_backlog_larger_than_one_read(): void
    {
        $run = $this->terminalRun();

        $fullPage = new Collection();
        foreach (range(1, 200) as $sequence) {
            $fullPage->push($this->event($sequence, 'bulk'));
        }

        $stub = $this->scriptedStore([
            new Collection(),
            $fullPage,
            new Collection([
                $this->event(201, 'tail-a'),
                $this->event(202, 'tail-b'),
            ]),
        ]);

        $events = iterator_to_array((new TurnEventStream($stub))->stream($run, 0), false);

        $this->assertCount(202, $events);
        $this->assertSame('tail-b', $events[201]['type']);
        $this->assertSame(202, $events[201]['_seq']);
        $this->assertSame(range(1, 202), array_column($events, '_seq'));
    }

    private function terminalRun(): AiTurnRun
    {
        $store = $this->app->make(TurnRunStore::class);
        $run = $store->open($this->conversation(), 'Hi');
        $store->markRunning($run);
        $store->finish($run, AiTurnRunStatus::Completed);

        return $run;
    }

    private function event(int $sequence, string $type): AiTurnEvent
    {
        return new AiTurnEvent([
            'sequence' => $sequence,
            'payload' => ['type' => $type],
        ]);
    }

    /**
     * A store whose reads follow a script, then come back empty forever.
     *
     * @param array<int, Collection<int, AiTurnEvent>> $pages
     */
    private function scriptedStore(array $pages): TurnRunStore
    {
        return new class ($pages) extends TurnRunStore {
            /** @param array<int, Collection<int, AiTurnEvent>> $pages */
            public function __construct(private array $pages)
            {
                parent::__construct();
            }

            public function eventsAfter(AiTurnRun $run, int $sequence, int $limit = 200): Collection
            {
                return array_shift($this->pages) ?? new Collection();
            }
        };
    }

    public function test_sequences_become_sse_ids_and_never_leak_into_the_payload(): void
    {
        $frames = iterator_to_array((new SseFrameEncoder())->encode([
            ['type' => 'content_block_delta', 'delta' => ['text' => 'Hi'], '_seq' => 7],
        ]), false);

        $this->assertSame("id: 7\ndata: " . json_encode([
            'type' => 'content_block_delta',
            'delta' => ['text' => 'Hi'],
        ]) . "\n\n", $frames[0]);
    }
}

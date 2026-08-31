# Stream Heartbeats and Durable Turns Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop a streamed chat turn from silently burning GPU after the browser leaves, and let a turn survive a page reload by running it as a queued job whose events the browser reads from a store.

**Architecture:** Part A bounds laravel/ai's blocking SSE read with a stream timeout, emitting a `Heartbeat` stream event on each idle window; the runner forwards it as a `heartbeat` turn event and `SseFrameEncoder` renders it as an SSE comment frame, so a dead socket is detected in seconds. Part B adds `ai_turn_runs`/`ai_turn_events`, a `RunConversationTurnJob` that drives the *existing* `continueConversation()` and appends each yielded event, and a `TurnEventStream` reader a host's endpoint streams from at any sequence. The synchronous path is untouched.

**Tech Stack:** PHP 8.3, Laravel package (Orchestra Testbench), PHPUnit 11, laravel/ai ^0.9, TypeScript (declarations + published client).

**Spec:** `docs/superpowers/specs/2026-08-31-durable-turns-and-heartbeats-design.md`

## Global Constraints

- PHP `^8.3`; Laravel `^12.62 || ^13.15`; laravel/ai `^0.9` (vendor copies in `ReasoningOpenAiCompatibleGateway` are pinned to 0.9.0 — re-check on upgrade).
- Namespace: `Jvjvjv\CodeTalker`.
- Tests use direct `Model::create(...)` (no in-package factories) and extend `Jvjvjv\CodeTalker\Tests\TestCase`.
- `ai_conversations.uuid` is not created by any package migration; any test that creates a conversation must add the column in `setUp()`:
  ```php
  if (!Schema::hasColumn('ai_conversations', 'uuid')) {
      Schema::table('ai_conversations', function ($table): void {
          $table->string('uuid')->nullable();
      });
  }
  ```
- `ai_llm_messages.turn_number` is a **string** column; use string values like `'1'`.
- `AiPersonaConversationService`'s constructor takes its **five** dependencies positionally (`AgentFactory`, `AiMemoryService`, `ConversationUsageService`, `RawExchangeContext`, `AiSystemProviderConfigurator`). Tests build anonymous subclasses with exactly that signature. New collaborators are constructed from those five **inside** the constructor — never added as parameters.
- `streamElapsedSeconds()` and `clientAborted()` stay `protected` on the service and reach `ConversationTurnRunner` as closures bound to `$this` (via `TurnGuards`).
- `continueConversation()` yields **structured event arrays**, never wire-encoded strings. `SseFrameEncoder` owns all framing.
- Every nested config read takes an inline default (`config('code-talker.conversations.heartbeat_seconds', 5)`) — Laravel skips `mergeConfigFrom` entirely when a host has cached config.
- Run tests with `vendor/bin/phpunit`. Run `npm run typecheck` after touching anything under `resources/js`.
- Commit messages end with:
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`
- Do **not** log security analysis in `CHANGELOG.md` or `README.md`.

---

## File Structure

**Part A — heartbeats**
- Create: `src/Services/LaravelAi/Streaming/Heartbeat.php` — the stream event; carries no payload beyond its id/timestamp.
- Create: `src/Services/LaravelAi/Concerns/HeartbeatsIdleSseReads.php` — the timeout-bounded SSE read with a partial-line buffer.
- Modify: `src/Services/LaravelAi/ReasoningOpenAiCompatibleGateway.php` — use the trait; pass a `Heartbeat` through `processTextStream()`.
- Modify: `src/Services/ChatBot/Conversation/ConversationTurnRunner.php` — treat a heartbeat as a tick, not an event.
- Modify: `src/Services/ChatBot/SseFrameEncoder.php` — render `heartbeat` as `": ping\n\n"`.
- Modify: `config/code-talker.php` — `conversations.heartbeat_seconds`.

**Part B — durable turns**
- Create: `database/migrations/2026_08_31_000001_create_ai_turn_runs_table.php`
- Create: `database/migrations/2026_08_31_000002_create_ai_turn_events_table.php`
- Create: `src/Enums/AiTurnRunStatus.php`
- Create: `src/Models/AiTurnRun.php`, `src/Models/AiTurnEvent.php`
- Create: `src/Services/Conversation/TurnRunStore.php` — the only writer; owns sequencing and the stop signal.
- Create: `src/Jobs/RunConversationTurnJob.php` — drives `continueConversation()`, appends events.
- Create: `src/Services/Conversation/TurnEventStream.php` — the reader generator.
- Create: `src/Console/Commands/PruneTurnEventsCommand.php`
- Modify: `src/Services/AiPersonaConversationService.php` — `dispatchTurn()`, `resumeTurn()`, `cancelTurn()`.
- Modify: `src/Services/ChatBot/SseFrameEncoder.php` — `id:` lines from `_seq`.
- Modify: `src/CodeTalkerServiceProvider.php` — register + schedule the prune command.
- Modify: `config/code-talker.php` — the `turns` block.

**Contract**
- Modify: `resources/js/types/code-talker.d.ts`, `resources/js/code-talker-stream.ts`, `README.md`, `CHANGELOG.md`.

---

## Task 1: Heartbeat stream event and the idle-read override

**Files:**
- Create: `src/Services/LaravelAi/Streaming/Heartbeat.php`
- Create: `src/Services/LaravelAi/Concerns/HeartbeatsIdleSseReads.php`
- Modify: `src/Services/LaravelAi/ReasoningOpenAiCompatibleGateway.php`
- Modify: `config/code-talker.php`
- Test: `tests/Feature/ReasoningOpenAiCompatibleGatewayTest.php`

**Interfaces:**
- Consumes: `Laravel\Ai\Streaming\Events\StreamEvent` (abstract `toArray(): array`); `Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents::parseServerSentEvents($streamBody): Generator`, reachable as `parent::parseServerSentEvents()` from a trait method.
- Produces:
  - `Heartbeat::__construct(string $id, int $timestamp)`, `toArray(): array` returning `type => 'heartbeat'`.
  - `HeartbeatsIdleSseReads::parseServerSentEvents($streamBody): Generator` yielding `array|Heartbeat`.

**Background the implementer needs:**

`ParsesServerSentEvents::readLine()` returns its buffer whenever a read yields `''`. If you simply add a timeout, a read that times out mid-frame hands the parser a partial line like `data: {"cho` — it starts with `data:`, so it is not skipped; `json_decode` fails silently; and the remainder arrives as a line that does *not* start with `data:` and is dropped. **The frame is lost.** The buffer must survive the idle window. That is the single most important property of this task.

Guzzle routes `stream => true` to `StreamHandler`, so the body wraps a real socket resource and `stream_set_timeout()` applies. When it does not (`Http::fake()` bodies are resource-backed too, but a `PumpStream` or a host's custom handler is not), fall back to the parent parser rather than degrading.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ReasoningOpenAiCompatibleGatewayTest.php`. Add these imports at the top of the file:

```php
use Jvjvjv\CodeTalker\Services\LaravelAi\Streaming\Heartbeat;
```

Then add the helper and tests:

```php
    /**
     * A gateway exposing the protected SSE parser, so a test can drive the
     * generator one step at a time. Stepping matters: the generator suspends on
     * each yield, which is what lets a single-threaded test write the second
     * half of a frame *after* observing the heartbeat for the gap.
     */
    private function parsingGateway(): object
    {
        return new class($this->app->make(Dispatcher::class)) extends ReasoningOpenAiCompatibleGateway
        {
            public function parse($body): \Generator
            {
                return $this->parseServerSentEvents($body);
            }
        };
    }

    public function test_an_idle_gap_yields_a_heartbeat_without_losing_the_frame_that_spans_it(): void
    {
        config()->set('code-talker.conversations.heartbeat_seconds', 1);

        [$readEnd, $writeEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        // Half a frame, then silence — exactly the shape that used to be lost.
        fwrite($writeEnd, 'data: {"choices":[{"delta":{"con');

        $events = $this->parsingGateway()->parse(Utils::streamFor($readEnd));

        // Runs until the first yield: the read times out and reports a beat.
        $events->rewind();
        $this->assertInstanceOf(Heartbeat::class, $events->current());
        $this->assertSame('heartbeat', $events->current()->toArray()['type']);

        // The rest of the frame arrives after the gap and must parse intact.
        fwrite($writeEnd, 'tent":"Hello"}}]}' . "\n\n");

        $events->next();
        $this->assertSame(
            [['delta' => ['content' => 'Hello']]],
            $events->current()['choices'],
        );

        fclose($writeEnd);
        $events->next();
        $this->assertFalse($events->valid());
    }

    public function test_heartbeats_are_disabled_by_a_zero_interval(): void
    {
        config()->set('code-talker.conversations.heartbeat_seconds', 0);

        $sse = 'data: {"choices":[{"delta":{"content":"Hi"}}]}' . "\n\n"
            . 'data: [DONE]' . "\n\n";

        $parsed = iterator_to_array($this->parsingGateway()->parse(Utils::streamFor($sse)), false);

        $this->assertCount(1, $parsed);
        $this->assertSame('Hi', $parsed[0]['choices'][0]['delta']['content']);
    }

    public function test_a_body_without_a_stream_resource_falls_back_to_the_parent_parser(): void
    {
        config()->set('code-talker.conversations.heartbeat_seconds', 1);

        // A PumpStream has no underlying resource, so detaching it would leave
        // nothing to read; the parser must delegate instead.
        $body = new \GuzzleHttp\Psr7\PumpStream(function (): string {
            static $sent = false;

            if ($sent) {
                return '';
            }

            $sent = true;

            return 'data: {"choices":[{"delta":{"content":"Hi"}}]}' . "\n\n";
        });

        $parsed = iterator_to_array($this->parsingGateway()->parse($body), false);

        $this->assertCount(1, $parsed);
        $this->assertSame('Hi', $parsed[0]['choices'][0]['delta']['content']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/ReasoningOpenAiCompatibleGatewayTest.php`
Expected: FAIL — `Heartbeat` class not found.

- [ ] **Step 3: Create the Heartbeat event**

Create `src/Services/LaravelAi/Streaming/Heartbeat.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi\Streaming;

use Laravel\Ai\Streaming\Events\StreamEvent;

/**
 * A tick emitted while the provider is silent.
 *
 * It carries no model output and never reaches the transcript or the logs. It
 * exists so something travels the stream during a long silent gap: the browser
 * gets a write (which is the only way PHP ever flips connection_aborted()), and
 * the turn's guards get an opportunity to run.
 */
class Heartbeat extends StreamEvent
{
    public function __construct(
        public string $id,
        public int $timestamp,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'heartbeat',
            'timestamp' => $this->timestamp,
        ];
    }
}
```

- [ ] **Step 4: Create the trait**

Create `src/Services/LaravelAi/Concerns/HeartbeatsIdleSseReads.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi\Concerns;

use Generator;
use Illuminate\Support\Str;
use Jvjvjv\CodeTalker\Services\LaravelAi\Streaming\Heartbeat;

/**
 * Bounds laravel/ai's blocking SSE read so a silent provider does not mean a
 * silent socket.
 *
 * ParsesServerSentEvents::readLine() reads a byte at a time and blocks until
 * the next one arrives. While it blocks, the whole turn is suspended inside it
 * — a heartbeat cannot be yielded from the runner, the service, or the host's
 * controller, because none of them are running. This is the only seam.
 *
 * The partial-line buffer is the load-bearing detail. readLine() treats an
 * empty read as end-of-line, so a naive timeout would hand the parser half a
 * frame (`data: {"cho`), which starts with `data:`, fails json_decode
 * silently, and leaves its remainder to be dropped as a line with no `data:`
 * prefix. The frame would be lost. Here the buffer survives the idle window.
 */
trait HeartbeatsIdleSseReads
{
    /**
     * Empty reads that are neither a timeout nor EOF before giving up. A well
     * behaved stream reports one of the three; this only stops a misbehaving
     * wrapper from spinning forever.
     */
    private const MAX_EMPTY_READS = 100;

    /**
     * @return Generator<int, array<string, mixed>|Heartbeat>
     */
    protected function parseServerSentEvents($streamBody): Generator
    {
        $seconds = (int) config('code-talker.conversations.heartbeat_seconds', 5);

        // Checked before detaching, because detach() cannot be undone: a body
        // with no resource behind it (a PumpStream, a host's custom handler)
        // must reach the parent parser with its body intact.
        if ($seconds <= 0 || ! is_string($streamBody->getMetadata('stream_type'))) {
            yield from parent::parseServerSentEvents($streamBody);

            return;
        }

        $resource = $streamBody->detach();

        if (! is_resource($resource)) {
            return;
        }

        try {
            yield from $this->readSseWithHeartbeats($resource, $seconds);
        } finally {
            // Nothing else holds it once detached.
            fclose($resource);
        }
    }

    /**
     * @param resource $resource
     * @return Generator<int, array<string, mixed>|Heartbeat>
     */
    private function readSseWithHeartbeats($resource, int $seconds): Generator
    {
        stream_set_timeout($resource, $seconds);

        $buffer = '';
        $emptyReads = 0;

        while (true) {
            $byte = fread($resource, 1);

            if ($byte === false) {
                return;
            }

            if ($byte === '') {
                // Checked before feof(), because a socket can report EOF after
                // a read timeout — treating that as the end would turn every
                // silent gap into a truncated turn.
                if (stream_get_meta_data($resource)['timed_out'] ?? false) {
                    $emptyReads = 0;

                    yield new Heartbeat(strtolower((string) Str::uuid7()), time());

                    continue;
                }

                if (feof($resource)) {
                    return;
                }

                if (++$emptyReads >= self::MAX_EMPTY_READS) {
                    return;
                }

                continue;
            }

            $emptyReads = 0;
            $buffer .= $byte;

            if ($byte !== "\n") {
                continue;
            }

            $line = trim($buffer);
            $buffer = '';

            if ($line === '' || ! str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, 5));

            if ($data === '[DONE]') {
                return;
            }

            $decoded = json_decode($data, true);

            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                yield $decoded;
            }
        }
    }
}
```

- [ ] **Step 5: Wire the trait into the gateway**

In `src/Services/LaravelAi/ReasoningOpenAiCompatibleGateway.php`, add the imports:

```php
use Jvjvjv\CodeTalker\Services\LaravelAi\Concerns\HeartbeatsIdleSseReads;
use Jvjvjv\CodeTalker\Services\LaravelAi\Streaming\Heartbeat;
```

Add the trait immediately inside the class declaration:

```php
class ReasoningOpenAiCompatibleGateway extends OpenAiCompatibleGateway
{
    use HeartbeatsIdleSseReads;
```

And in `processTextStream()`, make the first statement inside `foreach ($this->parseServerSentEvents($streamBody) as $data)`:

```php
            // A tick, not model output: forward it and read on. Everything
            // below this line assumes $data is a decoded provider frame.
            if ($data instanceof Heartbeat) {
                yield $data->withInvocationId($invocationId);

                continue;
            }
```

- [ ] **Step 6: Add the config key**

In `config/code-talker.php`, inside the `'conversations' => [` block, after `idle_timeout_minutes`:

```php
        // Seconds of provider silence before the turn emits a heartbeat.
        // Two things depend on it: intermediaries stop timing out during a
        // long gap, and PHP only flips connection_aborted() after a write to
        // a dead socket — so without a heartbeat an abandoned turn keeps
        // generating until the model's next event, which on a large context
        // can be minutes. Set to 0 to disable.
        'heartbeat_seconds' => (int) env('CODE_TALKER_HEARTBEAT_SECONDS', 5),
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/ReasoningOpenAiCompatibleGatewayTest.php`
Expected: PASS (the idle-gap test takes ~1s).

- [ ] **Step 8: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS — no existing test regresses. `Http::fake()` bodies are resource-backed but never idle, so they never produce a heartbeat.

- [ ] **Step 9: Commit**

```bash
git add src/Services/LaravelAi/Streaming/Heartbeat.php \
        src/Services/LaravelAi/Concerns/HeartbeatsIdleSseReads.php \
        src/Services/LaravelAi/ReasoningOpenAiCompatibleGateway.php \
        config/code-talker.php \
        tests/Feature/ReasoningOpenAiCompatibleGatewayTest.php
git commit -m "feat: emit heartbeats during idle provider reads"
```

---

## Task 2: The turn forwards heartbeats to the browser

**Files:**
- Modify: `src/Services/ChatBot/Conversation/ConversationTurnRunner.php`
- Modify: `src/Services/ChatBot/SseFrameEncoder.php`
- Modify: `resources/js/types/code-talker.d.ts`
- Modify: `README.md`
- Test: `tests/Feature/AiPersonaConversationServiceTest.php`, `tests/Feature/ChatTurnLibraryTest.php`

**Interfaces:**
- Consumes: `Heartbeat` from Task 1.
- Produces: the `['type' => 'heartbeat']` turn event, encoded as `": ping\n\n"`.

**Background the implementer needs:**

Three properties must hold together, and each has a test below:

1. A heartbeat is **not** appended to `$events`. That array is serialized into `ai_llm_messages.response_data.events`; a turn with minute-long gaps would otherwise log hundreds of ticks.
2. A heartbeat **does not** reset `$stepStartedAt`. Resetting it on a tick would make the max-duration guard unreachable forever.
3. A heartbeat **does** reach the max-duration guard. Today the guard only runs when a provider event arrives, so a stalled stream can sit well past `max_stream_seconds` unnoticed. This is a second bug the tick fixes, and the test below pins it.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/AiPersonaConversationServiceTest.php`. Add imports:

```php
use Jvjvjv\CodeTalker\Services\LaravelAi\Streaming\Heartbeat;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
```

(Some of these may already be imported; do not duplicate them.)

```php
    /**
     * Install a gateway that emits heartbeats between its text deltas — a
     * model that is slow to produce tokens rather than one that has stopped.
     */
    private function fakeHeartbeatingGateway(int $beats): void
    {
        CodeTalkerAgent::fake([]);

        $gateway = new class([], $beats) extends FakeTextGateway {
            public function __construct(array $responses, private int $beats)
            {
                parent::__construct($responses);
            }

            public function generateStreamStep(
                string $invocationId,
                TextProvider $provider,
                string $model,
                ?string $instructions,
                array $messages,
                array $tools,
                ?array $schema,
                ?TextGenerationOptions $options,
                ?int $timeout,
                StepContext $stepContext,
            ): Generator {
                yield (new StreamStart(uniqid('', true), $provider->name(), $model, time()))
                    ->withInvocationId($invocationId);

                for ($i = 0; $i < $this->beats; $i++) {
                    yield (new Heartbeat(uniqid('', true), time()))->withInvocationId($invocationId);
                }

                yield (new TextDelta(uniqid('', true), 'm1', 'Done', time()))
                    ->withInvocationId($invocationId);

                yield (new StreamEnd(uniqid('', true), 'stop', new Usage(), time()))
                    ->withInvocationId($invocationId);

                return new StepResponse(
                    'Done', [], FinishReason::Stop, new Usage(), new Meta($provider->name(), $model),
                );
            }
        };

        $manager = $this->app->make(AiManager::class);
        (Closure::bind(function () use ($gateway): void {
            $this->fakeAgentGateways[CodeTalkerAgent::class] = $gateway;
        }, $manager, $manager::class))();
    }

    public function test_a_heartbeat_reaches_the_browser_but_never_the_stored_events(): void
    {
        Queue::fake();
        $this->fakeHeartbeatingGateway(beats: 3);

        $persona = $this->makePersona();
        $service = $this->app->make(AiPersonaConversationService::class);

        $conversation = $service->startConversation($persona);
        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Hi'));

        $this->assertSame(3, count(array_filter($events, fn ($e) => ($e['type'] ?? null) === 'heartbeat')));

        // The stored event log is a record of what the model did, not of how
        // long it took to do it.
        $logged = AiLlmMessage::where('direction', 'response')->first()->response_data['events'];
        $this->assertNotContains('heartbeat', array_column($logged, 'type'));

        // The answer itself is unaffected.
        $this->assertSame('Done', AiConversationMessage::where('role', 'assistant')->first()->content);
    }

    public function test_the_max_duration_guard_trips_on_a_heartbeat_with_no_provider_event(): void
    {
        Queue::fake();
        config()->set('code-talker.conversations.max_stream_seconds', 60);
        $this->fakeHeartbeatingGateway(beats: 3);

        $persona = $this->makePersona();

        // Elapsed time only goes over budget after the StreamStart, so the
        // guard has nothing but heartbeats to trip on.
        $service = new class(
            $this->app->make(AgentFactory::class),
            $this->app->make(AiMemoryService::class),
            $this->app->make(ConversationUsageService::class),
            $this->app->make(RawExchangeContext::class),
            $this->app->make(AiSystemProviderConfigurator::class),
        ) extends AiPersonaConversationService {
            private int $calls = 0;

            protected function streamElapsedSeconds(float $startedAt): float
            {
                return ++$this->calls > 1 ? 9999.0 : 0.0;
            }
        };

        $conversation = $service->startConversation($persona);
        $events = $this->drainAndDecode($service->continueConversation($conversation, 'Hi'));

        $error = collect($events)->firstWhere('type', 'error');
        $this->assertNotNull($error);
        $this->assertSame('max_stream_duration', $error['reason']);
    }
```

Add to `tests/Feature/ChatTurnLibraryTest.php`:

```php
    public function test_a_heartbeat_is_encoded_as_a_comment_frame(): void
    {
        $frames = iterator_to_array((new SseFrameEncoder())->encode([
            ['type' => 'heartbeat'],
            ['type' => 'content_block_delta', 'delta' => ['text' => 'Hi']],
        ]), false);

        // A comment frame: every SSE consumer ignores it, including the
        // published client, which only reads lines beginning with "data:".
        $this->assertSame(": ping\n\n", $frames[0]);
        $this->assertStringStartsWith('data: {', $frames[1]);

        // A heartbeat is not an error, so the turn still terminates normally.
        $this->assertSame("data: [DONE]\n\n", $frames[2]);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/AiPersonaConversationServiceTest.php tests/Feature/ChatTurnLibraryTest.php`
Expected: FAIL — no `heartbeat` events are produced and the encoder emits a `data:` frame.

- [ ] **Step 3: Handle the heartbeat in the runner**

In `src/Services/ChatBot/Conversation/ConversationTurnRunner.php`, add the import:

```php
use Jvjvjv\CodeTalker\Services\LaravelAi\Streaming\Heartbeat;
```

Inside `foreach ($agent->stream($prompt) as $event) {`, directly after the `$guards->clientAborted()` block, insert:

```php
                    // A tick, not model output. It is deliberately not logged
                    // and not appended to $events — a turn with minute-long
                    // gaps would otherwise fill response_data with hundreds of
                    // them — and it deliberately does not reset the step
                    // clock, which would put the duration guard out of reach.
                    $isHeartbeat = $event instanceof Heartbeat;
```

Then guard the logging and accumulation that follow:

```php
                    if (! $isHeartbeat) {
                        Log::debug('Chat bot API stream event', [
                            // ...unchanged...
                        ]);

                        $events[] = $event;
                    }
```

Leave the `ToolResultEvent` / `StreamStart` / `ErrorEvent` / `ToolCallEvent` branches as they are — a `Heartbeat` matches none of them. After the max-duration guard block (so a tick can trip it), insert:

```php
                    if ($isHeartbeat) {
                        yield ['type' => 'heartbeat'];

                        continue;
                    }
```

- [ ] **Step 4: Handle the heartbeat in the encoder**

In `src/Services/ChatBot/SseFrameEncoder.php`, as the first statement of the `foreach` body:

```php
            // A comment frame, not a data frame: it exists to put a byte on
            // the wire during a silent gap, and every SSE consumer ignores it
            // without being taught to.
            if (($event['type'] ?? null) === 'heartbeat') {
                yield ": ping\n\n";

                continue;
            }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/AiPersonaConversationServiceTest.php tests/Feature/ChatTurnLibraryTest.php`
Expected: PASS

- [ ] **Step 6: Document the event**

In `README.md`, add a row to the turn-events table, after `message_stop`:

```markdown
| `heartbeat`             | — (encoded as an SSE comment, not a data frame)              |
```

And after the `stop_reason` paragraph added in 0.15.0, add:

```markdown
`heartbeat` fires while the provider is silent. `SseFrameEncoder` renders it as
`: ping` — an SSE comment — so browsers and the published client ignore it
without any handling. It is there so something reaches the socket during a long
gap: intermediaries stop timing out mid-answer, and PHP only flips
`connection_aborted()` after a write to a dead connection, so without it an
abandoned turn keeps generating until the model's next event. Set
`conversations.heartbeat_seconds` to `0` to disable. Detection costs two beats:
the first write marks the socket dead, the second observes it.
```

In `resources/js/types/code-talker.d.ts`, above the `ChatStreamEvent` union, add:

```typescript
/**
 * `heartbeat` is deliberately absent from this union. The server yields it as
 * a turn event, but `SseFrameEncoder` writes it as an SSE comment (`: ping`),
 * which never arrives as a message — so a wire consumer cannot receive one and
 * should not be made to handle it. A host consuming the events directly,
 * without the SSE encoding, will see `{ type: 'heartbeat' }`.
 */
```

- [ ] **Step 7: Verify the whole suite and the types**

Run: `vendor/bin/phpunit && npm run typecheck`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add src/Services/ChatBot/Conversation/ConversationTurnRunner.php \
        src/Services/ChatBot/SseFrameEncoder.php \
        resources/js/types/code-talker.d.ts README.md \
        tests/Feature/AiPersonaConversationServiceTest.php tests/Feature/ChatTurnLibraryTest.php
git commit -m "feat: forward stream heartbeats to the browser as comment frames"
```

---

## Task 3: Turn run schema, status enum, and models

**Files:**
- Create: `database/migrations/2026_08_31_000001_create_ai_turn_runs_table.php`
- Create: `database/migrations/2026_08_31_000002_create_ai_turn_events_table.php`
- Create: `src/Enums/AiTurnRunStatus.php`
- Create: `src/Models/AiTurnRun.php`
- Create: `src/Models/AiTurnEvent.php`
- Test: `tests/Feature/AiTurnRunModelTest.php`

**Interfaces:**
- Produces:
  - `AiTurnRunStatus` cases `Queued`, `Running`, `Completed`, `Failed`, `Cancelled`, `Abandoned`; method `isTerminal(): bool`.
  - `AiTurnRun` with `$fillable` = `ai_conversation_id, public_id, status, prompt, last_polled_at, cancel_requested_at, started_at, finished_at, error_message`; casts `status` to the enum and the four timestamps to `datetime`; `conversation(): BelongsTo`, `events(): HasMany`; a `booted()` hook assigning `public_id` as a ULID.
  - `AiTurnEvent` with `$fillable` = `ai_turn_run_id, sequence, payload, created_at`; `payload` cast to `array`; `$timestamps = false`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AiTurnRunModelTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/AiTurnRunModelTest.php`
Expected: FAIL — `AiTurnRunStatus` not found.

- [ ] **Step 3: Create the status enum**

Create `src/Enums/AiTurnRunStatus.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Enums;

enum AiTurnRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Abandoned = 'abandoned';

    /**
     * Whether the run is over. A reader stops on this — and only after one
     * final drain, because the job appends its last event before marking the
     * run finished.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Queued, self::Running => false,
            default => true,
        };
    }
}
```

- [ ] **Step 4: Create the migrations**

Create `database/migrations/2026_08_31_000001_create_ai_turn_runs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_turn_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('ai_conversation_id')->index();
            $table->string('status', 20)->index();
            $table->text('prompt');
            // The abandonment signal: connection_aborted() reports 0 in a
            // worker, so "nobody is reading this" is the only usable stand-in
            // for the browser having gone away.
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_turn_runs');
    }
};
```

Create `database/migrations/2026_08_31_000002_create_ai_turn_events_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_turn_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_turn_run_id')->index();
            $table->unsignedInteger('sequence');
            $table->json('payload');
            $table->timestamp('created_at')->nullable();

            // The reader asks for everything after a sequence it already
            // holds, so a duplicate would silently replay or skip output.
            $table->unique(['ai_turn_run_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_turn_events');
    }
};
```

- [ ] **Step 5: Create the models**

Create `src/Models/AiTurnRun.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;

/**
 * One attempt at a conversation turn, run detached from any HTTP connection.
 *
 * The run is what a browser reattaches to after a reload: it holds the prompt
 * the job replays, the status a reader stops on, and the two timestamps that
 * decide whether anyone still wants the answer.
 */
class AiTurnRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_conversation_id',
        'public_id',
        'status',
        'prompt',
        'last_polled_at',
        'cancel_requested_at',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            if (blank($run->public_id)) {
                $run->public_id = (string) Str::ulid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => AiTurnRunStatus::class,
            'last_polled_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AiTurnEvent::class, 'ai_turn_run_id')->orderBy('sequence');
    }
}
```

Create `src/Models/AiTurnEvent.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event a detached turn produced, in the shape continueConversation()
 * yields it. Framing is still the encoder's job, so what is stored here is a
 * structured event, never a wire frame.
 */
class AiTurnEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if ($event->created_at === null) {
                $event->created_at = Carbon::now();
            }
        });
    }

    protected $fillable = [
        'ai_turn_run_id',
        'sequence',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiTurnRun::class, 'ai_turn_run_id');
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/AiTurnRunModelTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_31_000001_create_ai_turn_runs_table.php \
        database/migrations/2026_08_31_000002_create_ai_turn_events_table.php \
        src/Enums/AiTurnRunStatus.php src/Models/AiTurnRun.php src/Models/AiTurnEvent.php \
        tests/Feature/AiTurnRunModelTest.php
git commit -m "feat: add turn run and turn event models"
```

---

## Task 4: TurnRunStore

**Files:**
- Create: `src/Services/Conversation/TurnRunStore.php`
- Modify: `config/code-talker.php`
- Test: `tests/Feature/TurnRunStoreTest.php`

**Interfaces:**
- Consumes: `AiTurnRun`, `AiTurnEvent`, `AiTurnRunStatus` from Task 3.
- Produces:
  ```php
  open(AiConversation $conversation, string $message): AiTurnRun
  markRunning(AiTurnRun $run): void
  append(AiTurnRun $run, array $event): int          // the assigned sequence
  finish(AiTurnRun $run, AiTurnRunStatus $status, ?string $error = null): void
  eventsAfter(AiTurnRun $run, int $sequence, int $limit = 200): Collection
  touchPoll(AiTurnRun $run): void
  requestCancel(AiTurnRun $run): void
  shouldStop(AiTurnRun $run): bool
  stopStatusFor(AiTurnRun $run): AiTurnRunStatus
  ```

**Background the implementer needs:**

`shouldStop()` is consulted on **every stream event**, so it must not put a query in the token loop. It re-reads at most every two seconds and returns a cached answer in between. The store instance is per-job, so the cache is per-run and needs no keying.

Abandonment is measured from `last_polled_at`, or from `created_at` while that is still null — a run dispatched a moment ago has no poller yet and must not be killed before its reader connects.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TurnRunStoreTest.php`:

```php
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

        $store = $this->store();
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/TurnRunStoreTest.php`
Expected: FAIL — `TurnRunStore` not found.

- [ ] **Step 3: Create the store**

Create `src/Services/Conversation/TurnRunStore.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Services\Conversation;

use Illuminate\Support\Collection;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiTurnEvent;
use Jvjvjv\CodeTalker\Models\AiTurnRun;

/**
 * The write side of a detached turn.
 *
 * One instance belongs to one run, for the life of the job that drives it —
 * which is what lets the sequence counter and the stop check live in memory
 * rather than in a query per token.
 */
class TurnRunStore
{
    private int $sequence = 0;

    private ?bool $cachedShouldStop = null;

    private float $shouldStopCheckedAt = 0.0;

    /**
     * Seconds between stop checks. shouldStop() is consulted on every stream
     * event, so an unthrottled read would put a database round-trip inside the
     * token loop for no benefit — nothing decides to cancel a turn faster than
     * this anyway.
     */
    private const STOP_CHECK_INTERVAL = 2.0;

    public function open(AiConversation $conversation, string $message): AiTurnRun
    {
        return AiTurnRun::create([
            'ai_conversation_id' => $conversation->id,
            'status' => AiTurnRunStatus::Queued,
            'prompt' => $message,
        ]);
    }

    public function markRunning(AiTurnRun $run): void
    {
        $run->forceFill([
            'status' => AiTurnRunStatus::Running,
            'started_at' => now(),
        ])->save();

        $this->sequence = (int) $run->events()->max('sequence');
    }

    /**
     * @param array<string, mixed> $event
     */
    public function append(AiTurnRun $run, array $event): int
    {
        $sequence = ++$this->sequence;

        AiTurnEvent::create([
            'ai_turn_run_id' => $run->id,
            'sequence' => $sequence,
            'payload' => $event,
        ]);

        return $sequence;
    }

    public function finish(AiTurnRun $run, AiTurnRunStatus $status, ?string $error = null): void
    {
        $run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'error_message' => $error,
        ])->save();
    }

    /**
     * @return Collection<int, AiTurnEvent>
     */
    public function eventsAfter(AiTurnRun $run, int $sequence, int $limit = 200): Collection
    {
        return AiTurnEvent::query()
            ->where('ai_turn_run_id', $run->id)
            ->where('sequence', '>', $sequence)
            ->orderBy('sequence')
            ->limit($limit)
            ->get();
    }

    public function touchPoll(AiTurnRun $run): void
    {
        AiTurnRun::query()->whereKey($run->id)->update(['last_polled_at' => now()]);
    }

    public function requestCancel(AiTurnRun $run): void
    {
        AiTurnRun::query()->whereKey($run->id)->update(['cancel_requested_at' => now()]);

        $this->cachedShouldStop = null;
    }

    /**
     * Whether the turn should stop generating: someone cancelled it, or nobody
     * is reading it any more.
     */
    public function shouldStop(AiTurnRun $run): bool
    {
        $now = microtime(true);

        if ($this->cachedShouldStop !== null && $now - $this->shouldStopCheckedAt < self::STOP_CHECK_INTERVAL) {
            return $this->cachedShouldStop;
        }

        $this->shouldStopCheckedAt = $now;

        return $this->cachedShouldStop = $this->readShouldStop($run);
    }

    /**
     * Which terminal status a stopped run earned.
     */
    public function stopStatusFor(AiTurnRun $run): AiTurnRunStatus
    {
        return $run->fresh()?->cancel_requested_at !== null
            ? AiTurnRunStatus::Cancelled
            : AiTurnRunStatus::Abandoned;
    }

    private function readShouldStop(AiTurnRun $run): bool
    {
        $fresh = $run->fresh();

        if ($fresh === null || $fresh->cancel_requested_at !== null) {
            return true;
        }

        $seconds = (int) config('code-talker.turns.abandon_after_seconds', 30);

        if ($seconds <= 0) {
            return false;
        }

        // Measured from created_at while nothing has polled yet: a run
        // dispatched a moment ago has no reader by definition, and killing it
        // before its reader connects would make the feature unusable.
        $since = $fresh->last_polled_at ?? $fresh->created_at;

        return $since !== null && $since->diffInSeconds(now()) > $seconds;
    }
}
```

- [ ] **Step 4: Add the config block**

In `config/code-talker.php`, before the closing `];`, add:

```php
    /*
    |--------------------------------------------------------------------------
    | Detached Turns
    |--------------------------------------------------------------------------
    |
    | A turn dispatched with AiPersonaConversationService::dispatchTurn() runs
    | as a queued job and writes its events to ai_turn_events, so a browser
    | reload resumes the turn instead of killing it. connection_aborted() is
    | meaningless in a worker, so "nobody has polled for abandon_after_seconds"
    | is what stops a turn nobody is waiting for.
    |
    */

    'turns' => [
        'queue' => env('CODE_TALKER_TURN_QUEUE'),
        'abandon_after_seconds' => (int) env('CODE_TALKER_TURN_ABANDON_SECONDS', 30),
        'poll_interval_ms' => (int) env('CODE_TALKER_TURN_POLL_MS', 250),
        'max_stream_seconds' => (int) env('CODE_TALKER_TURN_MAX_STREAM_SECONDS', 900),
        'retention_days' => (int) env('CODE_TALKER_TURN_RETENTION_DAYS', 7),
    ],
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/TurnRunStoreTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Services/Conversation/TurnRunStore.php config/code-talker.php tests/Feature/TurnRunStoreTest.php
git commit -m "feat: add TurnRunStore for detached turn events"
```

---

## Task 5: RunConversationTurnJob

**Files:**
- Create: `src/Jobs/RunConversationTurnJob.php`
- Test: `tests/Feature/RunConversationTurnJobTest.php`

**Interfaces:**
- Consumes: `TurnRunStore` (Task 4); `AiPersonaConversationService::usingCancellationCheck(callable): static` and `continueConversation(AiConversation, string): Generator` (both already exist).
- Produces: `RunConversationTurnJob::__construct(int $turnRunId)`; `handle(AiPersonaConversationService $chat, TurnRunStore $store): void`; `failed(?Throwable $e): void`.

**Background the implementer needs:**

The job constructor takes an **id**, not a model, so a serialized payload stays small and never carries a stale copy of the run.

The job calls the existing `continueConversation()`. There is deliberately no second turn implementation — everything the synchronous path does (system prompt, history, tool loop, `TurnRecorder`, memory job) happens here unchanged, and the 0.15.0 fixes mean a stopped run still persists its partial answer flagged incomplete.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RunConversationTurnJobTest.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Jobs\RunConversationTurnJob;
use Jvjvjv\CodeTalker\Models\AiConversationMessage;
use Jvjvjv\CodeTalker\Models\AiPersona;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Models\AiTurnRun;
use Jvjvjv\CodeTalker\Services\AiPersonaConversationService;
use Jvjvjv\CodeTalker\Services\Conversation\TurnRunStore;
use Jvjvjv\CodeTalker\Services\LaravelAi\CodeTalkerAgent;
use Jvjvjv\CodeTalker\Tests\TestCase;

class RunConversationTurnJobTest extends TestCase
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

    private function persona(): AiPersona
    {
        $system = AiSystem::create([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);

        return AiPersona::create([
            'ai_system_id' => $system->id,
            'name' => 'Test Bot',
            'slug' => 'test-bot',
            'prompt_template' => 'You are {{persona_name}}.',
            'is_active' => true,
        ]);
    }

    public function test_the_job_records_every_event_and_completes_the_run(): void
    {
        Queue::fake();
        CodeTalkerAgent::fake(['Hello there']);

        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($this->persona());

        $run = $this->app->make(TurnRunStore::class)->open($conversation, 'Hi');

        $this->app->make(RunConversationTurnJob::class, ['turnRunId' => $run->id])
            ->handle($service, $this->app->make(TurnRunStore::class));

        $run->refresh();
        $this->assertSame(AiTurnRunStatus::Completed, $run->status);
        $this->assertNotNull($run->finished_at);

        $types = $run->events()->pluck('payload.type')->all();
        $this->assertContains('content_block_delta', $types);
        $this->assertContains('message_stop', $types);

        // Sequences are contiguous from 1, which is what a resuming reader
        // relies on to know it missed nothing.
        $this->assertSame(range(1, $run->events()->count()), $run->events()->pluck('sequence')->all());

        // The turn itself behaved exactly as the synchronous path does.
        $this->assertSame('Hello there', AiConversationMessage::where('role', 'assistant')->first()->content);
    }

    public function test_a_cancelled_run_stops_and_is_marked_cancelled(): void
    {
        Queue::fake();
        CodeTalkerAgent::fake(['This answer is cancelled part way through']);

        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($this->persona());

        $store = $this->app->make(TurnRunStore::class);
        $run = $store->open($conversation, 'Hi');
        $store->requestCancel($run);

        $this->app->make(RunConversationTurnJob::class, ['turnRunId' => $run->id])
            ->handle($service, $this->app->make(TurnRunStore::class));

        $this->assertSame(AiTurnRunStatus::Cancelled, $run->fresh()->status);

        // 0.15.0's recorder keeps whatever the turn produced, flagged.
        $message = AiConversationMessage::where('role', 'assistant')->first();
        $this->assertNotNull($message);
        $this->assertTrue($message->metadata['incomplete']);
    }

    public function test_a_failed_job_marks_the_run_failed_so_a_reader_stops_waiting(): void
    {
        Queue::fake();

        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($this->persona());
        $run = $this->app->make(TurnRunStore::class)->open($conversation, 'Hi');

        $this->app->make(RunConversationTurnJob::class, ['turnRunId' => $run->id])
            ->failed(new \RuntimeException('worker died'));

        $run->refresh();
        $this->assertSame(AiTurnRunStatus::Failed, $run->status);
        $this->assertSame('worker died', $run->error_message);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/RunConversationTurnJobTest.php`
Expected: FAIL — `RunConversationTurnJob` not found.

- [ ] **Step 3: Create the job**

Create `src/Jobs/RunConversationTurnJob.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Models\AiTurnRun;
use Jvjvjv\CodeTalker\Services\AiPersonaConversationService;
use Jvjvjv\CodeTalker\Services\Conversation\TurnRunStore;
use Throwable;

/**
 * Runs one conversation turn detached from any HTTP connection.
 *
 * The turn logic is not duplicated here: this drives the same
 * continueConversation() the synchronous path uses and records what it yields.
 * What changes is only who is listening — and therefore how the turn learns
 * that nobody is.
 */
class RunConversationTurnJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The run's id rather than the model: a queued payload stays small, and the
     * job reads current state rather than a snapshot taken at dispatch.
     */
    public function __construct(public int $turnRunId)
    {
        $this->onQueue(config('code-talker.turns.queue') ?: null);
    }

    public function handle(AiPersonaConversationService $chat, TurnRunStore $store): void
    {
        $run = AiTurnRun::find($this->turnRunId);

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        $store->markRunning($run);

        try {
            $events = $chat
                ->usingCancellationCheck(fn (): bool => $store->shouldStop($run))
                ->continueConversation($run->conversation, $run->prompt);

            foreach ($events as $event) {
                $store->append($run, $event);
            }
        } catch (Throwable $exception) {
            $store->finish($run, AiTurnRunStatus::Failed, $exception->getMessage());

            throw $exception;
        }

        $store->finish(
            $run,
            $store->shouldStop($run) ? $store->stopStatusFor($run) : AiTurnRunStatus::Completed,
        );
    }

    /**
     * A worker that dies leaves a reader polling forever unless the run is
     * closed out here.
     */
    public function failed(?Throwable $exception): void
    {
        $run = AiTurnRun::find($this->turnRunId);

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        app(TurnRunStore::class)->finish(
            $run,
            AiTurnRunStatus::Failed,
            $exception?->getMessage(),
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/RunConversationTurnJobTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Jobs/RunConversationTurnJob.php tests/Feature/RunConversationTurnJobTest.php
git commit -m "feat: run a conversation turn as a queued job"
```

---

## Task 6: TurnEventStream reader and resumable SSE framing

**Files:**
- Create: `src/Services/Conversation/TurnEventStream.php`
- Modify: `src/Services/ChatBot/SseFrameEncoder.php`
- Test: `tests/Feature/TurnEventStreamTest.php`

**Interfaces:**
- Consumes: `TurnRunStore` (Task 4), `AiTurnRun`/`AiTurnRunStatus` (Task 3).
- Produces: `TurnEventStream::__construct(TurnRunStore $store)`; `stream(AiTurnRun $run, int $after = 0): Generator` yielding event arrays each carrying a `_seq` int.
- `SseFrameEncoder::encode()` emits `id: <_seq>\n` before the data line and strips `_seq` from the payload.

**Background the implementer needs:**

The **drain-after-terminal** ordering is the subtle part and has its own test. The job appends its last event and *then* marks the run finished. A reader that checks status before reading events can therefore see "finished" while the final event is still unread, and drop it. The order must be: read events → if empty, re-read status → if terminal, read events **once more** → only then stop.

`_seq` is encoder-only metadata. It is not part of the documented event vocabulary and must never reach the browser inside the JSON payload.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TurnEventStreamTest.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiSystem;
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/TurnEventStreamTest.php`
Expected: FAIL — `TurnEventStream` not found.

- [ ] **Step 3: Create the reader**

Create `src/Services/Conversation/TurnEventStream.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Services\Conversation;

use Generator;
use Jvjvjv\CodeTalker\Models\AiTurnEvent;
use Jvjvjv\CodeTalker\Models\AiTurnRun;

/**
 * The read side of a detached turn: replays a run's events from any point and
 * follows it live until it ends.
 *
 * Reading is also how a run stays alive. Each pass stamps last_polled_at, and a
 * run nobody stamps is abandoned — which is what closing a tab now means, since
 * connection_aborted() tells a queue worker nothing.
 */
class TurnEventStream
{
    public function __construct(
        private TurnRunStore $store,
    ) {
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(AiTurnRun $run, int $after = 0): Generator
    {
        $pollMicroseconds = max(1, (int) config('code-talker.turns.poll_interval_ms', 250)) * 1000;
        $heartbeatSeconds = (int) config('code-talker.conversations.heartbeat_seconds', 5);
        $maxSeconds = (int) config('code-talker.turns.max_stream_seconds', 900);

        $startedAt = microtime(true);
        $lastEmittedAt = $startedAt;

        while (true) {
            $this->store->touchPoll($run);

            $events = $this->store->eventsAfter($run, $after);

            if ($events->isNotEmpty()) {
                foreach ($events as $event) {
                    /** @var AiTurnEvent $event */
                    $after = $event->sequence;
                    $lastEmittedAt = microtime(true);

                    yield $event->payload + ['_seq' => $event->sequence];
                }

                continue;
            }

            // Nothing new. Read the status only now, and drain once more before
            // stopping: the job appends its last event and *then* marks the run
            // finished, so checking status first would drop that event.
            if ($run->fresh()?->status->isTerminal() ?? true) {
                foreach ($this->store->eventsAfter($run, $after) as $event) {
                    /** @var AiTurnEvent $event */
                    $after = $event->sequence;

                    yield $event->payload + ['_seq' => $event->sequence];
                }

                return;
            }

            if ($maxSeconds > 0 && microtime(true) - $startedAt > $maxSeconds) {
                yield [
                    'type' => 'error',
                    'message' => "The turn exceeded the maximum stream duration of {$maxSeconds}s.",
                    'reason' => 'max_stream_duration',
                ];

                return;
            }

            if ($heartbeatSeconds > 0 && microtime(true) - $lastEmittedAt >= $heartbeatSeconds) {
                $lastEmittedAt = microtime(true);

                // Provider-agnostic, unlike the gateway's own heartbeat: this
                // one fires for every provider, because it is measured against
                // the store rather than a socket.
                yield ['type' => 'heartbeat'];
            }

            usleep($pollMicroseconds);
        }
    }
}
```

- [ ] **Step 4: Emit sequences as SSE ids**

In `src/Services/ChatBot/SseFrameEncoder.php`, replace the `yield 'data: ' . json_encode($event) . "\n\n";` line with:

```php
            // `_seq` is framing metadata, not part of the event vocabulary: it
            // becomes the SSE id a reconnecting consumer resumes from, and
            // never reaches the browser inside the payload.
            $sequence = $event['_seq'] ?? null;
            unset($event['_seq']);

            yield ($sequence === null ? '' : 'id: ' . $sequence . "\n")
                . 'data: ' . json_encode($event) . "\n\n";
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/TurnEventStreamTest.php`
Expected: PASS

- [ ] **Step 6: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS — the encoder change is inert for events without `_seq`.

- [ ] **Step 7: Commit**

```bash
git add src/Services/Conversation/TurnEventStream.php src/Services/ChatBot/SseFrameEncoder.php \
        tests/Feature/TurnEventStreamTest.php
git commit -m "feat: add resumable turn event reader"
```

---

## Task 7: Service entry points

**Files:**
- Modify: `src/Services/AiPersonaConversationService.php`
- Test: `tests/Feature/AiPersonaConversationServiceTest.php`

**Interfaces:**
- Consumes: `TurnRunStore` (Task 4), `TurnEventStream` (Task 6), `RunConversationTurnJob` (Task 5).
- Produces:
  ```php
  dispatchTurn(AiConversation $conversation, string $message): AiTurnRun
  resumeTurn(AiTurnRun $run, int $after = 0): Generator
  cancelTurn(AiTurnRun $run): void
  ```

**Background the implementer needs:**

The constructor signature is load-bearing — `AiPersonaConversationServiceTest` builds anonymous subclasses with exactly five positional arguments. Resolve `TurnRunStore` and `TurnEventStream` **inside the method bodies** via `app()`, the way the constructor already builds its other collaborators from the five it is given. Do not add constructor parameters and do not add properties that need constructing.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/AiPersonaConversationServiceTest.php`. Add imports:

```php
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Jobs\RunConversationTurnJob;
use Jvjvjv\CodeTalker\Models\AiTurnRun;
use Jvjvjv\CodeTalker\Services\Conversation\TurnRunStore;
```

```php
    public function test_dispatching_a_turn_queues_a_job_against_a_new_run(): void
    {
        Queue::fake();

        $persona = $this->makePersona();
        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        $run = $service->dispatchTurn($conversation, 'Hi there');

        $this->assertSame(AiTurnRunStatus::Queued, $run->status);
        $this->assertSame('Hi there', $run->prompt);
        $this->assertNotEmpty($run->public_id);

        Queue::assertPushed(
            RunConversationTurnJob::class,
            fn (RunConversationTurnJob $job): bool => $job->turnRunId === $run->id,
        );
    }

    public function test_resuming_a_turn_streams_its_stored_events(): void
    {
        Queue::fake();
        config()->set('code-talker.turns.poll_interval_ms', 1);

        $persona = $this->makePersona();
        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        $run = $service->dispatchTurn($conversation, 'Hi');

        $store = $this->app->make(TurnRunStore::class);
        $store->markRunning($run);
        $store->append($run, ['type' => 'content_block_delta', 'delta' => ['text' => 'Hi']]);
        $store->finish($run, AiTurnRunStatus::Completed);

        $events = iterator_to_array($service->resumeTurn($run), false);

        $this->assertSame(['content_block_delta'], array_column($events, 'type'));
    }

    public function test_cancelling_a_turn_marks_it_for_the_worker(): void
    {
        Queue::fake();

        $persona = $this->makePersona();
        $service = $this->app->make(AiPersonaConversationService::class);
        $conversation = $service->startConversation($persona);

        $run = $service->dispatchTurn($conversation, 'Hi');
        $service->cancelTurn($run);

        $this->assertNotNull($run->fresh()->cancel_requested_at);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/AiPersonaConversationServiceTest.php`
Expected: FAIL — `dispatchTurn()` not defined.

- [ ] **Step 3: Add the methods**

In `src/Services/AiPersonaConversationService.php`, add the imports:

```php
use Jvjvjv\CodeTalker\Jobs\RunConversationTurnJob;
use Jvjvjv\CodeTalker\Models\AiTurnRun;
use Jvjvjv\CodeTalker\Services\Conversation\TurnEventStream;
use Jvjvjv\CodeTalker\Services\Conversation\TurnRunStore;
```

Add these methods after `continueConversation()`:

```php
    /**
     * Run a turn detached from the caller's connection.
     *
     * The turn becomes a queued job that writes its events to a store; the
     * browser reads them with resumeTurn() and can reconnect at any point. Use
     * this instead of continueConversation() when a turn is long enough that a
     * reload or a flaky connection should not destroy it.
     *
     * The store and reader are resolved here rather than injected: this
     * service's five-argument constructor is depended on by host apps and by
     * tests that subclass it, so collaborators are built from what it has.
     */
    public function dispatchTurn(AiConversation $conversation, string $message): AiTurnRun
    {
        $run = app(TurnRunStore::class)->open($conversation, $message);

        RunConversationTurnJob::dispatch($run->id);

        return $run;
    }

    /**
     * Stream a dispatched turn's events, starting after the given sequence.
     *
     * Yields the same structured events continueConversation() does, each with
     * a `_seq` the encoder turns into an SSE id. A browser that reconnects
     * passes back the last sequence it saw and misses nothing in between.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function resumeTurn(AiTurnRun $run, int $after = 0): Generator
    {
        yield from app(TurnEventStream::class)->stream($run, $after);
    }

    /**
     * Ask a running turn to stop.
     *
     * The worker notices within a couple of seconds and stops generating;
     * whatever the turn produced by then is persisted and flagged incomplete.
     */
    public function cancelTurn(AiTurnRun $run): void
    {
        app(TurnRunStore::class)->requestCancel($run);
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/AiPersonaConversationServiceTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Services/AiPersonaConversationService.php tests/Feature/AiPersonaConversationServiceTest.php
git commit -m "feat: add dispatchTurn, resumeTurn and cancelTurn"
```

---

## Task 8: Prune command and schedule

**Files:**
- Create: `src/Console/Commands/PruneTurnEventsCommand.php`
- Modify: `src/CodeTalkerServiceProvider.php`
- Test: `tests/Feature/PruneTurnEventsCommandTest.php`

**Interfaces:**
- Consumes: `AiTurnRun`, `AiTurnEvent`, `AiTurnRunStatus` (Task 3).
- Produces: the `ai:prune-turn-events` console command.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PruneTurnEventsCommandTest.php`:

```php
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

    private function run(AiTurnRunStatus $status, int $daysOld): AiTurnRun
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

        $old = $this->run(AiTurnRunStatus::Completed, daysOld: 10);
        $recent = $this->run(AiTurnRunStatus::Completed, daysOld: 1);
        $live = $this->run(AiTurnRunStatus::Running, daysOld: 10);

        $this->artisan('ai:prune-turn-events')->assertExitCode(0);

        $this->assertNull(AiTurnRun::find($old->id));
        $this->assertSame(0, AiTurnEvent::where('ai_turn_run_id', $old->id)->count());

        $this->assertNotNull(AiTurnRun::find($recent->id));

        // A long-running turn is not garbage, however old the row is.
        $this->assertNotNull(AiTurnRun::find($live->id));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/PruneTurnEventsCommandTest.php`
Expected: FAIL — the command is not registered.

- [ ] **Step 3: Create the command**

Create `src/Console/Commands/PruneTurnEventsCommand.php`:

```php
<?php

namespace Jvjvjv\CodeTalker\Console\Commands;

use Illuminate\Console\Command;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;
use Jvjvjv\CodeTalker\Models\AiTurnEvent;
use Jvjvjv\CodeTalker\Models\AiTurnRun;

class PruneTurnEventsCommand extends Command
{
    protected $signature = 'ai:prune-turn-events';

    protected $description = 'Delete finished turn runs and their events past the retention window';

    public function handle(): int
    {
        $days = (int) config('code-talker.turns.retention_days', 7);

        if ($days <= 0) {
            $this->info('Turn event retention is disabled; nothing pruned.');

            return self::SUCCESS;
        }

        $terminal = array_values(array_map(
            static fn (AiTurnRunStatus $status): string => $status->value,
            array_filter(AiTurnRunStatus::cases(), static fn (AiTurnRunStatus $s): bool => $s->isTerminal()),
        ));

        // Only finished runs: a turn still generating is not garbage, however
        // long it has been going.
        $runIds = AiTurnRun::query()
            ->whereIn('status', $terminal)
            ->where('created_at', '<', now()->subDays($days))
            ->pluck('id');

        if ($runIds->isEmpty()) {
            $this->info('No turn runs past retention.');

            return self::SUCCESS;
        }

        AiTurnEvent::query()->whereIn('ai_turn_run_id', $runIds)->delete();
        AiTurnRun::query()->whereIn('id', $runIds)->delete();

        $this->info("Pruned {$runIds->count()} turn run(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register and schedule it**

In `src/CodeTalkerServiceProvider.php`, add the import:

```php
use Jvjvjv\CodeTalker\Console\Commands\PruneTurnEventsCommand;
```

Add `PruneTurnEventsCommand::class` to the `$this->commands([...])` array alongside `PruneProviderExchangesCommand::class`, and inside the `if (config('code-talker.schedule', true))` block:

```php
            Schedule::command('ai:prune-turn-events')
                ->dailyAt('03:15')
                ->withoutOverlapping();
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/PruneTurnEventsCommandTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Console/Commands/PruneTurnEventsCommand.php src/CodeTalkerServiceProvider.php \
        tests/Feature/PruneTurnEventsCommandTest.php
git commit -m "feat: add ai:prune-turn-events command"
```

---

## Task 9: Client resumption and release documentation

**Files:**
- Modify: `resources/js/code-talker-stream.ts`
- Modify: `resources/js/types/code-talker.d.ts`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Test: `tests/Feature/FrontendAssetPublishingTest.php` (must keep passing unchanged)

**Interfaces:**
- Consumes: the `id:` framing from Task 6.
- Produces: a `lastEventId` the caller can pass back when reconnecting.

**Background the implementer needs:**

`FrontendAssetPublishingTest` enforces that `code-talker-stream.ts` imports nothing but browser APIs and relative paths. Do not add a dependency.

The client parses frames by splitting on `\n\n` and reading lines that begin with `data:`. Comment frames (`: ping`) and `id:` lines are already ignored — the change is to *record* the id, not to start handling new frame types.

- [ ] **Step 1: Track the sequence in the client**

In `resources/js/code-talker-stream.ts`, in the frame dispatch function, before the existing `data:` filtering, read the id:

```typescript
    const idLine = frame.split('\n').find((line) => line.startsWith('id:'));

    if (idLine !== undefined) {
        const parsed = Number.parseInt(idLine.slice(3).trim(), 10);

        if (!Number.isNaN(parsed)) {
            // Recorded so a caller reconnecting after a dropped connection can
            // resume from here instead of replaying the whole turn.
            callbacks.onSequence?.(parsed);
        }
    }
```

Add `onSequence?: (sequence: number) => void;` to the callbacks interface in the same file and to the declarations in `resources/js/types/code-talker.d.ts`, documented as:

```typescript
    /**
     * The sequence of the last event received, present only for a turn
     * dispatched with `dispatchTurn()`. Pass it back as `after` when
     * reconnecting so the turn resumes rather than replays.
     */
    onSequence?: (sequence: number) => void;
```

- [ ] **Step 2: Verify the types and the publishing constraints**

Run: `npm run typecheck && vendor/bin/phpunit tests/Feature/FrontendAssetPublishingTest.php`
Expected: PASS

- [ ] **Step 3: Document the durable path in the README**

In `README.md`, after the "Interrupted turns" section added in 0.15.0, add:

````markdown
### Running a turn as a job

`continueConversation()` ties the turn to the caller's connection: close the
tab and the turn stops, reload and it is gone. For turns long enough that this
matters, dispatch the turn instead and stream it from its store.

```php
// Start it. Returns an AiTurnRun; `public_id` is the handle to put in a URL.
$run = $chat->dispatchTurn($conversation, $request->string('message'));

// Stream it — from the start, or from wherever the browser left off.
foreach ($encoder->encode($chat->resumeTurn($run, $after)) as $frame) {
    echo $frame;
    ob_get_level() > 0 && ob_flush();
    flush();
}

// Stop it early.
$chat->cancelTurn($run);
```

Each event is framed with an SSE `id:` carrying its sequence. A browser that
reconnects passes the last sequence it saw back as `after`, and the turn
resumes rather than replaying. The published client reports it via
`onSequence`.

A dispatched turn needs a queue worker. Because `connection_aborted()` reports
0 in a worker, a run stops when nobody has read it for
`turns.abandon_after_seconds` (default 30) — so closing the tab still stops
generation, and a reload inside that window reattaches to the same run.
`ai:prune-turn-events` clears finished runs past `turns.retention_days`.
````

Add the `turns.*` keys to the configuration section alongside `raw_exchanges.*`.

- [ ] **Step 4: Extend the 0.15.0 changelog entry**

In `CHANGELOG.md`, add to the existing `## [0.15.0]` entry's **New Features** list:

```markdown
- Turns now emit a heartbeat while the provider is silent (`conversations.heartbeat_seconds`, default 5, `0` to disable). It is encoded as an SSE comment, so existing clients ignore it; it keeps intermediaries from timing out mid-answer and lets an abandoned turn be noticed in seconds rather than minutes. Currently emitted for `openai-compatible` and `lm-studio` systems; a turn dispatched as a job heartbeats for every provider.
- Added `AiPersonaConversationService::dispatchTurn()`, `resumeTurn()` and `cancelTurn()`: a turn can run as a queued job that records its events, so a browser reload resumes it instead of killing it. Events are framed with an SSE `id:` carrying their sequence, and the published client reports it through a new `onSequence` callback. Requires the two new migrations and a queue worker.
- Added `ai:prune-turn-events` (scheduled daily at 03:15) to clear finished turn runs past `turns.retention_days`.
```

And to **Bug Fixes**:

```markdown
- The maximum-stream-duration guard now applies during provider silence. It was only evaluated when an event arrived, so a stalled stream could run well past `conversations.max_stream_seconds` before anything noticed.
```

Add to **Breaking Changes**:

```markdown
- Two new migrations (`ai_turn_runs`, `ai_turn_events`) ship with this release. Re-publish migrations and run them, even if you do not use the dispatched-turn API — `ai:prune-turn-events` is scheduled by default and expects the tables.
```

Remove the **Known Issues** bullet about abandoned connections only being noticed on the next write; it no longer describes the release.

- [ ] **Step 5: Verify everything**

Run: `vendor/bin/phpunit && npm run typecheck`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add resources/js/code-talker-stream.ts resources/js/types/code-talker.d.ts README.md CHANGELOG.md
git commit -m "docs: document heartbeats and dispatched turns for 0.15.0"
```

---

## Self-Review Notes

Checked against the spec:

- **Part A components** — `Heartbeat` (Task 1), `HeartbeatsIdleSseReads` with the partial-line buffer and the fallback (Task 1), gateway wiring (Task 1), runner tick handling (Task 2), encoder comment frame (Task 2), config key (Task 1). ✓
- **Part A consequences** — the two-beat detection cost and the openai-compatible-only coverage are documented in the README (Task 2) and the CHANGELOG (Task 9); the duration-guard improvement has a test (Task 2). ✓
- **Part B data model** — both migrations, the enum, both models, and the no-`last_sequence` decision (Task 3). ✓
- **Part B components** — `TurnRunStore` with throttled `shouldStop()` (Task 4), the job with `failed()` (Task 5), `TurnEventStream` with the drain-after-terminal ordering (Task 6), the three service methods (Task 7), the prune command (Task 8). ✓
- **Contract** — `id:` framing (Task 6), the `heartbeat`-absent-from-the-union note (Task 2), client `onSequence` (Task 9), README and CHANGELOG (Tasks 2 and 9). ✓
- **Error handling table** — non-resource body (Task 1 test), split frame (Task 1 test), dead worker (Task 5 test), unpolled run (Task 4 test), reader ceiling (Task 6 implementation). The "pruned run" row needs no code: `stream()` treats a missing run as terminal via `$run->fresh()?->status->isTerminal() ?? true`.
- **Type consistency** — `shouldStop()`/`stopStatusFor()` are named identically in Tasks 4, 5 and 6; `_seq` is produced in Task 6 and consumed by the encoder in the same task; `turnRunId` is the job's property name in Tasks 5 and 7.

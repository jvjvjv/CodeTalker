# Design: stream heartbeats and durable turns

**Date:** 2026-08-31
**Status:** Approved

## Purpose

A streamed chat turn currently lives and dies with the browser's HTTP
connection, and the server only notices the browser is gone when it next writes
to the socket. With a large-context local model, gaps between provider events
run 100–500 seconds, so a turn abandoned at t=14s keeps generating until t=104s
and is then discarded.

Two fixes already landed on this branch (release 0.15.0) and are assumed here:

- A turn cut short is persisted with whatever it produced, flagged
  `metadata.incomplete` with an `incomplete_reason`.
- A cut-short turn reports `stop_reason: incomplete` and logs
  `AiInteractionStatus::Aborted`, never `success`.

Those stop the data loss. They do not stop the waste, and they do not make a
turn survive a page reload. This design covers the two remaining fixes:

- **Part A — heartbeats.** Write to the socket during silent gaps, so a dead
  connection is noticed in seconds rather than minutes and intermediaries stop
  timing out mid-gap.
- **Part B — durable turns.** Run a turn as a queued job that appends its
  events to a store the browser reads from, so a reload resumes the turn
  instead of killing it.

## Part A — heartbeats during silent gaps

### The constraint

The blocking read is `Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents::readLine()`,
a byte-at-a-time blocking read on the PSR-7 response body. While it blocks,
`ConversationTurnRunner`'s `foreach` over `$agent->stream()` is suspended, so a
heartbeat cannot be yielded from the runner, the service, or the host's
controller. The override point has to be inside the read.

Three facts establish feasibility, each verified against the vendored code:

1. Guzzle routes a request with `stream => true` to `StreamHandler`, not the
   cURL handler (`Proxy::wrapStreaming`), so the response body wraps a **real
   PHP socket resource** and `stream_set_timeout()` applies.
2. `TextGenerationLoop::stream()` forwards every yielded event verbatim
   (`foreach ($stream as $event) { yield $event; }`), so a package-defined
   `StreamEvent` subclass reaches the runner untouched.
3. `readLine()` returns its buffer when a read yields `''`. A naive timeout
   therefore hands `parseServerSentEvents()` a **partial** line — `data: {"cho` —
   which fails `json_decode` silently, and the remainder arrives as a line not
   starting with `data:` and is dropped. **The frame is lost.** Any timeout
   implementation must carry a partial-line buffer across idle windows.

### Components

**`Services/LaravelAi/Streaming/Heartbeat`** — a `StreamEvent` subclass whose
`toArray()` reports `type: 'heartbeat'`. It exists only to travel through
laravel/ai's loop; it never reaches the transcript or the logs.

**`Services/LaravelAi/Concerns/HeartbeatsIdleSseReads`** — overrides
`parseServerSentEvents()`:

- Reads `conversations.heartbeat_seconds`. When `<= 0`, delegates to the parent
  implementation and returns — the feature is off and behaviour is unchanged.
- Confirms the body is resource-backed by checking `getMetadata('stream_type')`
  is a non-empty string **before** detaching. When it is not (`Http::fake()`,
  a `PumpStream`, a host's custom handler), delegates to the parent. Checking
  first matters: `detach()` is not reversible, so a failed detach would leave
  no body to fall back to.
- Otherwise `detach()`es the resource, applies `stream_set_timeout($resource,
  $seconds)`, and runs its own byte loop with a `$buffer` that survives
  timeouts. On `fread() === ''` it consults
  `stream_get_meta_data($resource)['timed_out']`: true yields a `Heartbeat`
  and continues reading into the same buffer; false plus `feof()` ends the
  stream. A bounded consecutive-empty-read counter guards against a wrapper
  that reports neither.
- `fclose()`s the detached resource in a `finally`. Nothing else holds it once
  detached, so the trait owns closing it.

Cost is unchanged: one syscall per byte, exactly as the parent already does.
`stream_set_timeout` is chosen over `stream_select` for that reason — a select
per byte would double the syscall count.

The generator's yielded type widens from `array` to `array|Heartbeat`, which is
type-safe and cannot collide with a provider payload the way a sentinel array
key could.

**`ReasoningOpenAiCompatibleGateway`** uses the trait, and `processTextStream()`
gains one branch: a `Heartbeat` is re-yielded with the invocation id and the
loop continues.

**`ConversationTurnRunner`** treats a heartbeat as a tick, not an event:

- It is **not** appended to `$events` and not logged — it would flood
  `ai_llm_messages.response_data.events`.
- It does **not** reset `$stepStartedAt`.
- The max-duration guard **is** evaluated on a heartbeat iteration, then the
  runner yields `['type' => 'heartbeat']` and continues.

That last point fixes a second latent bug: today the duration guard only runs
when a provider event arrives, so a stalled stream can sit well past
`max_stream_seconds` unnoticed. Heartbeats make the guard real.

**`SseFrameEncoder`** encodes a `heartbeat` event as `": ping\n\n"` — an SSE
comment frame — and continues without touching its terminal-state tracking.
`EventSource` ignores comment frames, and the published client filters on lines
starting with `data:` (`code-talker-stream.ts`), so both ignore it for free.

### Consequences to expect

- **Abort detection takes two heartbeats**, roughly 10s at the default. PHP
  only flips `connection_aborted()` once a write to the dead socket has been
  attempted: the first heartbeat is that write, the second observes the result.
  This is still two orders of magnitude better than the observed 100–500s.
- **Coverage is `openai-compatible` and `lm-studio` only.** That is the one
  gateway this package overrides; Anthropic, OpenAI and Gemini use laravel/ai's
  own. Copying more vendor code to cover them is explicitly rejected — Part B's
  reader-side heartbeat is provider-agnostic and closes the gap properly.

## Part B — durable turns

A turn should not die because a tab closed. The job runs it; a store holds its
events; the browser reads from the store at whatever sequence it left off.

Chosen transport: **DB-backed, host-polled.** No new infrastructure beyond the
queue and database a host already runs. Broadcasting was rejected because
replay-after-reload still requires a stored backlog, making it the DB store
*plus* a Reverb/Pusher/Redis dependency rather than an alternative to it. A
cache-backed list was rejected because eviction silently loses a turn and
file/array drivers do not share state between the worker and web processes.

The durable path is **additive**. `continueConversation()` is unchanged and
still works synchronously; the job calls it, so there is one turn
implementation rather than two.

### Data model

**`ai_turn_runs`**

| Column | Notes |
| --- | --- |
| `id` | |
| `public_id` | ULID, unique — the handle a host puts in a URL, following `ai_conversations.public_id` |
| `ai_conversation_id` | FK, indexed |
| `status` | string(20), cast to `AiTurnRunStatus` |
| `prompt` | text — the user message the job replays |
| `last_polled_at` | nullable timestamp — the abandonment signal |
| `cancel_requested_at` | nullable timestamp — an explicit cancel |
| `started_at`, `finished_at` | nullable timestamps |
| `error_message` | nullable text |
| timestamps | |

**`ai_turn_events`**

| Column | Notes |
| --- | --- |
| `id` | |
| `ai_turn_run_id` | FK, indexed |
| `sequence` | unsigned int; unique with the run id |
| `payload` | json — one event array as `continueConversation()` yields it |
| `created_at` | |

**`AiTurnRunStatus`**: `Queued`, `Running`, `Completed`, `Failed`, `Cancelled`,
`Abandoned`, with `isTerminal()`.

There is deliberately **no `last_sequence` column** on the run. The reader asks
for events after a sequence it already holds and terminates on status plus an
empty read; a per-event counter update would be a second write per event
buying nothing.

### Components

**`Services/Conversation/TurnRunStore`** — the only writer, owned by the job:

```
open(AiConversation, string $message): AiTurnRun
markRunning(AiTurnRun): void
append(AiTurnRun, array $event): int      // returns the assigned sequence
finish(AiTurnRun, AiTurnRunStatus, ?string $error = null): void
eventsAfter(AiTurnRun, int $sequence, int $limit = 200): Collection
touchPoll(AiTurnRun): void
requestCancel(AiTurnRun): void
shouldStop(AiTurnRun): bool
```

The sequence counter lives in memory on the store instance, seeded at `open()`,
because the job is the sole writer for the life of a run.

`shouldStop()` is the cancellation signal and is **throttled**: it re-queries at
most every two seconds and returns its cached answer in between. It is
consulted on every stream event, so an unthrottled query would put a database
round-trip in the token loop.

**`Jobs/RunConversationTurnJob`** — constructed with the run id (not the model,
so a queued payload stays small). `handle()` marks the run running, binds
`usingCancellationCheck(fn () => $store->shouldStop($run))`, appends every
yielded event, and finishes the run with the status the stop reason implies:
`Cancelled` when a cancel was requested, `Abandoned` when nobody polled,
`Completed` otherwise. `failed()` marks the run `Failed` with the exception
message, so a worker that dies does not leave a reader waiting forever.

**`Services/Conversation/TurnEventStream`** — the reader generator a host's
endpoint consumes. Each pass: touch `last_polled_at`, read events after the
cursor, yield each with its sequence attached, advance the cursor. When a read
comes back empty it re-reads the run's status, and **if terminal it drains once
more before breaking** — the job appends an event and then marks the run
terminal, so reading status before events would drop the final event. While the
run is live and quiet it yields `['type' => 'heartbeat']` on the same
`heartbeat_seconds` cadence, which is what makes Part A's benefit
provider-agnostic on this path. A `turns.max_stream_seconds` ceiling bounds the
generator so an endpoint cannot hang forever.

**`AiPersonaConversationService`** gains three methods, none of which change the
existing five-argument constructor (`AiPersonaConversationServiceTest` builds
anonymous subclasses against that exact signature):

```
dispatchTurn(AiConversation, string $message): AiTurnRun
resumeTurn(AiTurnRun, int $after = 0): Generator
cancelTurn(AiTurnRun): void
```

**`SseFrameEncoder`** emits `id: <sequence>\n` before the data line for any
event carrying a `_seq` key, and strips `_seq` from the JSON payload. `_seq` is
encoder-only metadata, never part of the documented event shape. This gives an
`EventSource` consumer automatic `Last-Event-ID` resumption; the published
fetch-based client reads it manually.

**`Console/Commands/PruneTurnEventsCommand`** (`ai:prune-turn-events`) deletes
terminal runs older than `turns.retention_days`, cascading to their events.
Scheduled daily at 03:15, alongside `ai:prune-provider-exchanges`.

### Cancellation semantics

`connection_aborted()` is meaningless in a worker — it reports 0 — so a
detached turn needs a different signal. A run is stopped when either:

- `cancel_requested_at` is set (an explicit host-driven cancel), or
- nothing has polled it for `turns.abandon_after_seconds` (default 30),
  measured from `last_polled_at`, or from `created_at` while that is still null
  so a run gets a grace period before its first reader connects.

Closing the tab therefore stops the GPU within ~30s, and a reload *inside* that
window reattaches to the same run rather than starting a new one. That resume
property is the whole point of Part B.

### Host wiring

The package still ships no routes. The documented shape is two endpoints:

```php
// Start a turn.
$run = $chat->dispatchTurn($conversation, $request->string('message'));
return ['run' => $run->public_id];

// Stream it, from the beginning or from wherever the browser left off.
return response()->stream(function () use ($chat, $run, $after, $encoder) {
    foreach ($encoder->encode($chat->resumeTurn($run, $after)) as $frame) {
        echo $frame;
        ob_get_level() > 0 && ob_flush();
        flush();
    }
}, headers: [...]);
```

A reload calls the second endpoint again with the last sequence it saw.

## Configuration

```php
'conversations' => [
    // ...
    'heartbeat_seconds' => (int) env('CODE_TALKER_HEARTBEAT_SECONDS', 5),
],

'turns' => [
    'queue' => env('CODE_TALKER_TURN_QUEUE'),                          // null = default
    'abandon_after_seconds' => (int) env('CODE_TALKER_TURN_ABANDON_SECONDS', 30),
    'poll_interval_ms' => (int) env('CODE_TALKER_TURN_POLL_MS', 250),
    'max_stream_seconds' => (int) env('CODE_TALKER_TURN_MAX_STREAM_SECONDS', 900),
    'retention_days' => (int) env('CODE_TALKER_TURN_RETENTION_DAYS', 7),
],
```

Every nested read uses an inline default, because Laravel skips
`mergeConfigFrom` entirely when a host has cached config — a host that
published `code-talker.php` before these keys existed would otherwise resolve
`null` in production only.

## Frontend contract

- **README** — the turn-events table gains `heartbeat`, with a note that
  `SseFrameEncoder` renders it as a `: ping` comment frame; a new section
  documents running a turn as a job and resuming it.
- **`code-talker.d.ts`** — deliberately does **not** add `heartbeat` to the
  `ChatStreamEvent` union. That union describes what arrives over the wire, and
  a comment frame never does; declaring it would force consumers to handle a
  case they cannot receive. A comment in the declarations records why, so the
  omission does not read as an oversight later.
- **`code-talker-stream.ts`** — unchanged for heartbeats (it already filters on
  `data:`); gains sequence tracking so a caller can resume.

## Error handling

| Failure | Behaviour |
| --- | --- |
| Body is not resource-backed | Trait delegates to the parent parser; no heartbeats, no regression |
| Stream times out mid-frame | Partial line held in the buffer; the frame completes on the next read |
| Worker dies mid-run | `failed()` marks the run `Failed`; the reader sees a terminal status and stops |
| Nobody polls a live run | Run stops within `abandon_after_seconds`; partial output persisted by the 0.15.0 recorder |
| Reader outlives `max_stream_seconds` | Generator yields a terminal `error` frame and returns |
| Reader asks for a pruned run | Treated as terminal; the host renders the stored transcript instead |

## Testing

**Part A**
- A socket pair with a deliberate idle gap: heartbeats are yielded during the
  gap, and a frame split across the gap arrives intact (the regression the
  partial-line buffer exists to prevent).
- A non-resource-backed body falls through to the parent parser unchanged.
- `heartbeat_seconds = 0` disables the override entirely.
- The runner yields a `heartbeat` frame, records no heartbeat in
  `ai_llm_messages`, and does not reset the step clock.
- The encoder renders `: ping\n\n` and does not treat it as terminal.
- The max-duration guard trips on a heartbeat with no provider event.

**Part B**
- `dispatchTurn()` creates a queued run and dispatches the job.
- The job appends every event the service yields, in order, and finishes the
  run `Completed`.
- A reader replays from sequence 0 and from mid-sequence, and a second reader
  starting at `after: N` sees exactly the tail — the reload case.
- A reader that stops polling abandons the run; the turn stops and its partial
  output is persisted and flagged incomplete.
- The final event is never dropped when the job finishes between the reader's
  event read and its status read.
- An explicit cancel stops the run and marks it `Cancelled`.
- `failed()` marks the run `Failed` and the reader terminates.
- `ai:prune-turn-events` removes terminal runs past retention and their events,
  and leaves live runs alone.

## Out of scope

- A broadcasting transport. The store is the prerequisite for one; adding it
  later needs no schema change.
- Multi-viewer fan-out. Two readers on one run both work, but `last_polled_at`
  makes them indistinguishable, so neither can be cancelled independently.
- Resuming a turn whose worker died mid-generation. The run is marked failed
  and the browser sees an error; regenerating is the host's call.
- Extending gateway-level heartbeats to Anthropic/OpenAI/Gemini. Part B's
  reader-side heartbeat covers those paths without copying vendor code.

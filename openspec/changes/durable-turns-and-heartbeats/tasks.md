## 1. Heartbeats during idle provider reads

- [ ] 1.1 Add `Services/LaravelAi/Streaming/Heartbeat`, a `StreamEvent` reporting `type: 'heartbeat'`
- [ ] 1.2 Add `Services/LaravelAi/Concerns/HeartbeatsIdleSseReads` overriding `parseServerSentEvents()` with a `stream_set_timeout()`-bounded read whose partial-line buffer survives an idle window
- [ ] 1.3 Delegate to the parent parser when the interval is `0` or the body has no stream resource, checked before `detach()`
- [ ] 1.4 Check `timed_out` before `feof()`, so a read timeout is never mistaken for the end of the stream
- [ ] 1.5 Use the trait in `ReasoningOpenAiCompatibleGateway` and pass a `Heartbeat` through `processTextStream()`
- [ ] 1.6 Add `conversations.heartbeat_seconds` (default 5, `0` disables)

## 2. The turn forwards heartbeats

- [ ] 2.1 `ConversationTurnRunner` yields `['type' => 'heartbeat']` without appending to `$events` or logging it
- [ ] 2.2 A heartbeat does not reset the step clock, and does reach the max-duration guard
- [ ] 2.3 `SseFrameEncoder` renders `heartbeat` as `": ping\n\n"`, non-terminal
- [ ] 2.4 Document the event in the README; record in `code-talker.d.ts` why it is absent from `ChatStreamEvent`

## 3. Turn run schema and models

- [ ] 3.1 Migration: `ai_turn_runs` — `public_id`, `ai_conversation_id`, `status`, `prompt`, `last_polled_at`, `cancel_requested_at`, `started_at`, `finished_at`, `error_message`
- [ ] 3.2 Migration: `ai_turn_events` — `ai_turn_run_id`, `sequence`, `payload`, unique on (run, sequence)
- [ ] 3.3 Add `AiTurnRunStatus` with `isTerminal()`
- [ ] 3.4 Add `AiTurnRun` (ULID `public_id`, enum + timestamp casts, `events()` ordered by sequence) and `AiTurnEvent`

## 4. TurnRunStore

- [ ] 4.1 `open`, `markRunning`, `append`, `finish`, `eventsAfter`, `touchPoll`, `requestCancel`
- [ ] 4.2 `shouldStop()` throttled to one read every two seconds, so it never queries per token
- [ ] 4.3 Abandonment measured from `last_polled_at`, or `created_at` while that is null
- [ ] 4.4 `stopStatusFor()` distinguishing `Cancelled` from `Abandoned`
- [ ] 4.5 Add the `turns` config block

## 5. RunConversationTurnJob

- [ ] 5.1 Constructed with the run id; drives the existing `continueConversation()` and appends every yielded event
- [ ] 5.2 Binds `usingCancellationCheck()` to the store's stop signal
- [ ] 5.3 Finishes the run `Completed`, `Cancelled` or `Abandoned` as the stop reason implies
- [ ] 5.4 `failed()` marks the run `Failed`, so a dead worker does not leave a reader polling forever

## 6. TurnEventStream and resumable framing

- [ ] 6.1 Reader generator replaying from any sequence and following a live run
- [ ] 6.2 Drains once more after seeing a terminal status, so the job's final event is never dropped
- [ ] 6.3 Stamps `last_polled_at` each pass; emits a provider-agnostic `heartbeat` while quiet; bounded by `turns.max_stream_seconds`
- [ ] 6.4 `SseFrameEncoder` emits `id: <sequence>` from `_seq` and strips it from the payload

## 7. Service entry points

- [ ] 7.1 `dispatchTurn()`, `resumeTurn()`, `cancelTurn()`, resolving collaborators inside the methods so the five-argument constructor is unchanged

## 8. Retention

- [ ] 8.1 `ai:prune-turn-events` removing terminal runs past `turns.retention_days` and their events, leaving live runs alone
- [ ] 8.2 Register the command and schedule it daily at 03:15

## 9. Contract and documentation

- [ ] 9.1 `code-talker-stream.ts` reports the SSE `id:` through a new `onSequence` callback
- [ ] 9.2 README: the `heartbeat` event, the detached-turn workflow, and the `turns.*` config
- [ ] 9.3 CHANGELOG: fold both halves into the 0.15.0 entry, including the new migrations under Breaking Changes

## Why

A streamed turn lives and dies with the browser's HTTP connection, and the server only learns the browser is gone when it next writes to the socket. With a large-context local model, gaps between provider events run 100–500 seconds, so a turn abandoned at t=14s keeps generating until t=104s — burning GPU the whole time — and is then thrown away.

Two fixes already in this release stop the *data* loss: an interrupted turn is now persisted with whatever it produced and flagged incomplete, and it is logged as `aborted` rather than as a clean success. Neither stops the waste, and neither makes a turn survive a reload. This change addresses both.

The blocking read is `laravel/ai`'s `ParsesServerSentEvents::readLine()`, a byte-at-a-time blocking read on the response body. While it blocks, the whole turn is suspended inside it — nothing in this package, and nothing in a host's controller, is running to emit anything. That constraint decides the shape of both halves of this change.

## What Changes

- Adds a heartbeat during provider silence. A `HeartbeatsIdleSseReads` trait bounds the SSE read with `stream_set_timeout()` and emits a `Heartbeat` stream event on each idle window; `ConversationTurnRunner` forwards it as a `heartbeat` turn event and `SseFrameEncoder` renders it as an SSE comment (`: ping`), which every existing consumer ignores without being taught to. Configurable via `conversations.heartbeat_seconds` (default 5, `0` disables).
- The heartbeat carries a partial-line buffer across idle windows. Without it a timeout mid-frame would hand the parser half a `data:` line, which fails `json_decode` silently and leaves its remainder to be dropped — losing the frame outright.
- Fixes a latent bug the heartbeat exposes: the maximum-stream-duration guard was only evaluated when a provider event arrived, so a stalled stream could run well past `conversations.max_stream_seconds` unnoticed. Heartbeats give the guard something to run on.
- Adds detached turns. `AiPersonaConversationService::dispatchTurn()` runs a turn as a queued job that appends each yielded event to `ai_turn_events`; `resumeTurn()` streams them back from any sequence, so a browser reload resumes the turn instead of killing it. `cancelTurn()` stops one early.
- Frames each detached event with an SSE `id:` carrying its sequence, so a reconnecting consumer resumes from `Last-Event-ID` rather than replaying the turn. The published client reports it through a new `onSequence` callback.
- Replaces `connection_aborted()` for detached turns, which reports 0 in a queue worker and would mean a turn never stops. A run is abandoned when nothing has read it for `turns.abandon_after_seconds` (default 30) — so closing the tab still stops generation, and a reload inside that window reattaches to the same run.
- Adds `ai:prune-turn-events` (scheduled daily at 03:15) to clear finished runs past `turns.retention_days`.

The synchronous path is untouched. `continueConversation()` behaves exactly as before, and the job calls it — there is one turn implementation, not two.

## Capabilities

### Modified Capabilities
- `chat-turn-library`: adds `heartbeat` to the turn event vocabulary and its SSE comment-frame encoding; adds the detached-turn lifecycle (dispatch, resume from a sequence, cancel, abandon) alongside the existing synchronous call; adds sequence-bearing SSE framing.

## Impact

- **Code**: `Heartbeat` stream event and `HeartbeatsIdleSseReads` trait; `ReasoningOpenAiCompatibleGateway`, `ConversationTurnRunner`, and `SseFrameEncoder` modified; `AiTurnRun`/`AiTurnEvent` models with two migrations; `TurnRunStore`, `TurnEventStream`, `RunConversationTurnJob`, `PruneTurnEventsCommand`; three new methods on `AiPersonaConversationService`.
- **Migrations**: `ai_turn_runs` and `ai_turn_events`. Hosts re-publish and migrate even if they never call `dispatchTurn()` — `ai:prune-turn-events` is scheduled by default and expects the tables.
- **Config**: `conversations.heartbeat_seconds`; a new `turns` block (`queue`, `abandon_after_seconds`, `poll_interval_ms`, `max_stream_seconds`, `retention_days`).
- **Frontend contract**: README turn-events table gains `heartbeat`; `code-talker-stream.ts` gains `onSequence`. `heartbeat` is deliberately **not** added to the `ChatStreamEvent` union in `code-talker.d.ts` — that union describes what arrives over the wire, and a comment frame never does.
- **Scope limit**: gateway-level heartbeats reach `openai-compatible` and `lm-studio` systems only, because that is the one gateway this package overrides. A detached turn heartbeats for every provider, because its beat is measured against the store rather than a socket.
- **Not in scope**: a broadcasting transport (the store is its prerequisite and needs no schema change to add one); multi-viewer fan-out; resuming a turn whose worker died mid-generation.

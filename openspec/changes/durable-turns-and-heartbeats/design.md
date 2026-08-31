# Design

The full working design, with the vendored-code analysis behind it, is
`docs/superpowers/specs/2026-08-31-durable-turns-and-heartbeats-design.md`; the
implementation plan is
`docs/superpowers/plans/2026-08-31-durable-turns-and-heartbeats.md`. What
follows is the record of the decisions those documents made and why.

## Why the heartbeat has to live inside the SSE read

`ParsesServerSentEvents::readLine()` blocks on a byte-at-a-time read of the
response body. While it blocks, `ConversationTurnRunner`'s `foreach` over
`$agent->stream()` is suspended inside it, and so is everything upstream — the
service, and the host's controller loop. A heartbeat therefore cannot be
yielded from any of them. The read is the only seam.

Three facts were verified against the vendored code before committing to this:

1. Guzzle routes `stream => true` to `StreamHandler`, not the cURL handler
   (`Proxy::wrapStreaming`), so the body wraps a real socket resource and
   `stream_set_timeout()` applies.
2. `TextGenerationLoop::stream()` forwards every yielded event verbatim, so a
   package-defined `StreamEvent` subclass reaches the runner untouched.
3. `readLine()` returns its buffer on an empty read. A naive timeout would
   therefore hand the parser a partial line (`data: {"cho`) that starts with
   `data:`, fails `json_decode` silently, and leaves its remainder to be
   dropped as a line without the prefix. **The frame would be lost.** The
   partial-line buffer that survives an idle window is the load-bearing detail
   of the whole trait.

`stream_set_timeout` was chosen over `stream_select` because the parent already
reads a byte at a time; a `select` per byte would double the syscall count for
no benefit. The timeout flag is checked *before* `feof()`, because a socket can
report EOF after a read timeout — treating that as the end would turn every
silent gap into a truncated turn.

A body with no resource behind it (a `PumpStream`, a host's custom handler)
delegates to the parent parser. That check happens **before** `detach()`,
because detaching cannot be undone: a failed detach would leave no body to fall
back to.

## Why detection costs two heartbeats

PHP only flips `connection_aborted()` after a write to a dead socket has been
attempted. The first heartbeat is that write; the second observes the result.
At the default interval that is roughly ten seconds — against the 100–500
seconds observed in the field, which is the number this change exists to fix.

## Why the durable transport is the database

The alternatives were weighed and rejected:

- **Broadcasting** (Reverb/Pusher/Redis) still needs a stored backlog to
  replay after a reload, which makes it the database store *plus* an
  infrastructure dependency this package does not currently have — not an
  alternative to the store. The store is its prerequisite, and adding a
  broadcast layer later needs no schema change.
- **A cache-backed list** needs no migration, but eviction silently loses a
  turn mid-generation, and file/array drivers do not share state between the
  queue worker and the web process at all.

The database costs a poll query per active viewer at `poll_interval_ms`. That
is the price of having no new infrastructure, and it is paid only by hosts who
opt into the detached path.

## Why abandonment is a poll timestamp

`connection_aborted()` reports 0 in a worker, so a detached turn has no signal
that its reader left. Reading is therefore what keeps a run alive: every pass
of `TurnEventStream` stamps `last_polled_at`, and a run nobody stamps for
`turns.abandon_after_seconds` stops.

The timestamp is measured from `created_at` while `last_polled_at` is still
null. A run dispatched a moment ago has no reader by definition, and killing it
before its first reader connects would make the feature unusable.

`shouldStop()` is consulted on every stream event, so it re-reads at most every
two seconds and returns a cached answer in between — an unthrottled check would
put a database round-trip inside the token loop.

## Why the reader drains after seeing a terminal status

The job appends its last event and *then* marks the run finished. A reader that
checks status before reading events can see "finished" while the final event is
still unread, and drop it — silently, on every turn. The order is therefore:
read events, and only if empty re-read the status, and if terminal read events
once more before stopping.

## Why `heartbeat` is absent from the TypeScript event union

`ChatStreamEvent` describes what arrives over the wire. `SseFrameEncoder`
writes a heartbeat as an SSE comment, which never arrives as a message, so a
wire consumer cannot receive one and should not be made to handle it. A host
consuming the structured events directly, without the SSE encoding, does see
`{ type: 'heartbeat' }` — the README documents it there. A comment in the
declarations records the omission so it does not read as an oversight.

## Why there is no `last_sequence` column

The reader asks for events after a sequence it already holds and stops on
status plus an empty read. A per-event counter update on the run would be a
second write per event buying nothing.

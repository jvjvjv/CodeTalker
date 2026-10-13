## Purpose

Defines the chat turn as a direct library call: a host drives a conversation turn by calling package services and consuming structured events, rather than relying on package-owned HTTP routes, controllers, or pages.

## Requirements

### Requirement: A turn is a library call yielding structured events

The chat turn SHALL be driven directly as a library call that yields structured events, so a host can deliver them over any transport.

#### Scenario: Driving a turn

- **WHEN** a host continues a conversation with a message
- **THEN** it receives an iterable of event arrays, each carrying a `type`
- **AND** the event vocabulary is `status`, `message_start`, `content_block_delta`, `reasoning_block_delta`, `message_delta`, `message_stop`, `tool_use_progress`, `page_reload`, `heartbeat`, and `error`

#### Scenario: No transport encoding leaks into the turn

- **WHEN** a turn yields an event
- **THEN** it is a structured array, not a wire-encoded string, so the caller chooses the encoding

### Requirement: Server-sent events remain available as a helper

The package SHALL ship an encoder that turns the event stream into the documented server-sent-event framing, so a host preserving the existing wire format does not reimplement it.

#### Scenario: Encoding a finished turn

- **WHEN** a completed turn's events are encoded
- **THEN** each is emitted as `data: <json>\n\n`
- **AND** the stream ends with the literal `data: [DONE]\n\n`

#### Scenario: An error terminates the stream on its own

- **WHEN** a turn emits an error event
- **THEN** the encoded stream ends without the `[DONE]` sentinel

#### Scenario: A heartbeat is encoded as a comment

- **WHEN** a heartbeat event is encoded
- **THEN** it is emitted as the comment frame `: ping\n\n` rather than a data frame
- **AND** it does not terminate the stream, so a turn carrying heartbeats still ends with the sentinel

#### Scenario: A sequenced event carries a resumable id

- **WHEN** an event carrying a sequence is encoded
- **THEN** the frame is preceded by `id: <sequence>`
- **AND** the sequence does not appear inside the event's JSON payload

### Requirement: Cancellation is supplied by the caller

Turn cancellation SHALL be injectable, so a turn driven outside a web request can be cancelled by whatever signal that context has.

#### Scenario: Default web behavior

- **WHEN** no cancellation check is supplied
- **THEN** the turn stops when the client connection is aborted

#### Scenario: A caller supplies its own check

- **WHEN** a host supplies a cancellation check
- **THEN** the turn consults it, and stops when it reports cancellation
- **AND** whatever the turn produced before stopping is still persisted

### Requirement: Persona access rules are enforced by the service

Rules that previously lived in the HTTP layer SHALL be enforced where the turn is started, so they cannot be lost by a host writing its own controller.

#### Scenario: An inactive persona is refused

- **WHEN** a conversation is started for an inactive persona
- **THEN** it fails rather than opening one

#### Scenario: A persona requiring visitor identity is given none

- **WHEN** a conversation is started for a persona requiring visitor identity without a name and email
- **THEN** it fails with an error naming the requirement

#### Scenario: The chat hash stays current

- **WHEN** a conversation is continued
- **THEN** its shareable hash is present and current, as it was when the package owned the endpoint

### Requirement: Presentation queries remain available

The queries a chat UI needs SHALL remain callable, so removing the pages does not delete the logic behind them.

#### Scenario: Rendering a transcript

- **WHEN** a host lists a conversation's visible messages
- **THEN** system messages are excluded and each message carries its role, content, reasoning, and blocks

#### Scenario: Listing a visitor's conversations

- **WHEN** a host lists an authenticated user's conversations for a persona
- **THEN** it receives them most-recently-updated first, with their titles and timestamps

### Requirement: A silent provider does not mean a silent connection

A turn SHALL emit a heartbeat while the provider produces nothing, so an intermediary does not time out mid-answer and an abandoned connection is detected in seconds rather than minutes.

#### Scenario: The provider goes quiet mid-turn

- **WHEN** a configured heartbeat interval elapses with no provider event
- **THEN** the turn yields a `heartbeat` event
- **AND** continues reading the provider stream from where it paused

#### Scenario: A frame spanning a silent gap is not lost

- **WHEN** the provider stops part way through emitting an event frame and resumes after a heartbeat
- **THEN** the completed frame is parsed as a single event, with nothing dropped

#### Scenario: Heartbeats are not part of the record

- **WHEN** a turn that emitted heartbeats is logged
- **THEN** the stored provider events contain no heartbeat

#### Scenario: Heartbeats are disabled

- **WHEN** the heartbeat interval is configured as `0`
- **THEN** the turn reads the provider stream exactly as it did before heartbeats existed

### Requirement: The stream duration guard applies during silence

The maximum-stream-duration guard SHALL be evaluated while the provider is silent, not only when it emits.

#### Scenario: A stalled stream exceeds its budget

- **WHEN** a turn passes its maximum stream duration with no provider event since
- **THEN** the turn is stopped and reported as a duration failure, without waiting for the provider to emit again

### Requirement: A turn can outlive the connection that started it

A turn SHALL be dispatchable as a background run whose events are recorded, so a caller can disconnect and reattach without destroying it.

#### Scenario: Dispatching a turn

- **WHEN** a host dispatches a turn for a conversation
- **THEN** it receives a run with a shareable public identifier
- **AND** the turn is executed in the background, recording each event it yields in order

#### Scenario: Reattaching after a reload

- **WHEN** a caller streams a run from the last sequence it received
- **THEN** it receives every event recorded after that sequence and nothing it already had
- **AND** continues to receive events until the run ends

#### Scenario: The final event survives the run finishing mid-read

- **WHEN** a run records its last event and finishes between a reader's two reads
- **THEN** the reader still receives that event before stopping

#### Scenario: Cancelling a dispatched turn

- **WHEN** a host cancels a run
- **THEN** the turn stops generating
- **AND** whatever it produced is persisted and flagged incomplete

### Requirement: A dispatched turn nobody is reading stops

Because connection state is unavailable to a background worker, a dispatched run SHALL treat the absence of a reader as the signal to stop.

#### Scenario: The reader goes away

- **WHEN** no caller has read a run for the configured abandonment window
- **THEN** the turn stops generating and the run is recorded as abandoned

#### Scenario: A run is not killed before its first reader connects

- **WHEN** a run has been dispatched but never read, and the abandonment window has not yet elapsed since it was created
- **THEN** it continues running

#### Scenario: Reading keeps a run alive

- **WHEN** a caller is streaming a run
- **THEN** the run is not abandoned for as long as the reading continues

#### Scenario: A worker dies mid-run

- **WHEN** the process running a turn fails
- **THEN** the run is recorded as failed, so a reader stops rather than waiting indefinitely

### Requirement: Recorded turn events are retained for a bounded window

Recorded runs and their events SHALL be prunable, so the store does not grow without limit.

#### Scenario: Pruning finished runs

- **WHEN** the retention command runs
- **THEN** finished runs older than the retention window are removed with their events
- **AND** runs that are still executing are left alone, however old

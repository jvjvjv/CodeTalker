## Purpose

Defines the chat turn as a direct library call: a host drives a conversation turn by calling package services and consuming structured events, rather than relying on package-owned HTTP routes, controllers, or pages.

## Requirements

### Requirement: A turn is a library call yielding structured events

The chat turn SHALL be driven directly as a library call that yields structured events, so a host can deliver them over any transport.

#### Scenario: Driving a turn

- **WHEN** a host continues a conversation with a message
- **THEN** it receives an iterable of event arrays, each carrying a `type`
- **AND** the event vocabulary is unchanged from the previously documented stream: `status`, `message_start`, `content_block_delta`, `reasoning_block_delta`, `message_delta`, `message_stop`, and `error`

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

### Requirement: Cancellation is supplied by the caller

Turn cancellation SHALL be injectable, so a turn driven outside a web request can be cancelled by whatever signal that context has.

#### Scenario: Default web behavior

- **WHEN** no cancellation check is supplied
- **THEN** the turn stops when the client connection is aborted

#### Scenario: A caller supplies its own check

- **WHEN** a host supplies a cancellation check
- **THEN** the turn consults it, and stops when it reports cancellation
- **AND** whatever the turn produced before stopping is still persisted

### Requirement: Chat bot access rules are enforced by the service

Rules that previously lived in the HTTP layer SHALL be enforced where the turn is started, so they cannot be lost by a host writing its own controller.

#### Scenario: An inactive bot is refused

- **WHEN** a conversation is started for an inactive chat bot
- **THEN** it fails rather than opening one

#### Scenario: A bot requiring visitor identity is given none

- **WHEN** a conversation is started for a bot requiring visitor identity without a name and email
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

- **WHEN** a host lists an authenticated user's conversations for a bot
- **THEN** it receives them most-recently-updated first, with their titles and timestamps

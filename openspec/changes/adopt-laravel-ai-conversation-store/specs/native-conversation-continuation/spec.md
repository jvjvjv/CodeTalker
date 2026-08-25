## ADDED Requirements

### Requirement: The package provides laravel/ai's conversation store

The package SHALL implement `Laravel\Ai\Contracts\ConversationStore` over its own conversation tables and bind it over the framework default, so agents resolve conversation history from Code Talker's records.

#### Scenario: The binding replaces the framework default

- **WHEN** the container resolves the conversation store contract
- **THEN** it receives the package's implementation rather than the framework's database store

#### Scenario: History is reconstructed as message objects

- **WHEN** an agent's stored history is read
- **THEN** user turns carrying attachments are returned as user messages with those attachments
- **AND** assistant turns carrying tool calls are returned as an assistant message plus a tool-result message
- **AND** turns are returned oldest-first, limited to the most recent messages

#### Scenario: System messages are not replayed as history

- **WHEN** a conversation's stored messages include one with the `system` role
- **THEN** it is excluded from replayed history, because it is supplied to the agent as instructions rather than as a turn

#### Scenario: An empty assistant turn is skipped

- **WHEN** a stored assistant turn has neither content nor tool calls
- **THEN** it is omitted, because providers reject empty assistant messages

### Requirement: An agent can continue a stored conversation

The package's agent SHALL support resuming a stored conversation, so a host can continue one without rebuilding the transcript itself.

#### Scenario: Resuming by conversation

- **WHEN** a host resumes an agent onto a stored conversation and prompts it
- **THEN** the prior turns are included in the request

#### Scenario: Continuation messages are preserved

- **WHEN** an agent is resumed onto a conversation and additional messages are appended within the turn
- **THEN** the request carries the stored history followed by the appended messages, in that order

#### Scenario: Reading history does not enable automatic persistence

- **WHEN** the package resumes an agent onto a conversation for history replay
- **THEN** no conversation participant is attached
- **AND** the framework's remembering middleware does not run, so each turn is persisted exactly once

### Requirement: Recorded turns are replayable

Persisted assistant turns SHALL carry the structure needed to reconstruct them, so history replay does not silently lose tool activity.

#### Scenario: A turn using tools is recorded

- **WHEN** a turn that called tools is recorded
- **THEN** the stored message carries its tool calls, tool results, and token usage alongside its text and reasoning

#### Scenario: An aborted turn is still recorded

- **WHEN** a turn is cut short by a client disconnect or the maximum stream duration
- **THEN** whatever it produced is still persisted, unchanged from previous behavior

## REMOVED Requirements

### Requirement: Transcript rebuilding from message text

**Reason**: `TranscriptBuilder` reconstructed history as bare user/assistant text pairs. It could not represent a tool call, a tool result, or an attachment, which made attachment replay impossible.

**Migration**: History now comes from the conversation store. Hosts calling `TranscriptBuilder` directly should resolve the store contract and call `getLatestConversationMessages()`.

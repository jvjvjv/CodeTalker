## MODIFIED Requirements

### Requirement: Recorded turns are replayable

Persisted assistant turns SHALL carry the structure needed to reconstruct them, so history replay does not silently lose tool activity. When a recorded turn carries tool calls without a matching recorded result, replay SHALL omit those calls, so a turn cut short between tool results does not leave the conversation permanently rejected by the provider.

#### Scenario: A turn using tools is recorded

- **WHEN** a turn that called tools is recorded
- **THEN** the stored message carries its tool calls, tool results, and token usage alongside its text and reasoning

#### Scenario: An aborted turn is still recorded

- **WHEN** a turn is cut short by a client disconnect or the maximum stream duration
- **THEN** whatever it produced is still persisted, unchanged from previous behavior

#### Scenario: A turn aborted between two tool results is replayed

- **WHEN** a stored assistant turn carries two tool calls and only one tool result, and the conversation is continued
- **THEN** the replayed history carries the matched call and its result only
- **AND** the next turn is accepted by the provider

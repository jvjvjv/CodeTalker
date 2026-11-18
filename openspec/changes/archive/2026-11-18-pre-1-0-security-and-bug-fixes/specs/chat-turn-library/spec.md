## ADDED Requirements

### Requirement: A fresh install can open a conversation

Every column the package's models write SHALL be created by the package's own migrations, so a host that publishes and runs them can start and continue a conversation without adding schema by hand.

#### Scenario: Starting a conversation after installing

- **WHEN** a host runs the published migrations on an empty database and starts a conversation
- **THEN** the conversation is created with a unique identifier and a shareable hash derived from it

#### Scenario: Upgrading a host that already has conversations

- **WHEN** the migration adding the identifier runs on a database with existing conversations
- **THEN** every existing row receives a distinct identifier, so regenerating any row's hash does not collide with another

### Requirement: Visitor identity fields are sanitised before use

The visitor name and email a host passes when starting a conversation SHALL be trimmed, stripped of control characters including line breaks, and length-checked before they are interpolated into a system prompt or written to the database. A value that fails SHALL be refused with a readable error rather than a database exception.

#### Scenario: A visitor name containing a line break

- **WHEN** a conversation is started with a visitor name containing a newline followed by prompt-like text
- **THEN** the stored name and the rendered system prompt contain the name on a single line with the line break removed

#### Scenario: A visitor name longer than the column

- **WHEN** a conversation is started with a visitor name longer than 255 characters
- **THEN** it is refused with an error naming the field, before any row is inserted

## MODIFIED Requirements

### Requirement: Persona access rules are enforced by the service

Rules that previously lived in the HTTP layer SHALL be enforced where the turn is started, so they cannot be lost by a host writing its own controller. Continuing a conversation SHALL re-check that its persona and system are still usable, and SHALL report a failure as a terminal `error` event rather than an exception escaping the event stream. A conversation that was marked completed by the idle sweep SHALL be returned to the active state when it is continued, so it is swept and analysed again later.

#### Scenario: An inactive persona is refused

- **WHEN** a conversation is started for an inactive persona
- **THEN** it fails rather than opening one

#### Scenario: A persona requiring visitor identity is given none

- **WHEN** a conversation is started for a persona requiring visitor identity without a name and email
- **THEN** it fails with an error naming the requirement

#### Scenario: The chat hash stays current

- **WHEN** a conversation is continued
- **THEN** its shareable hash is present and current, as it was when the package owned the endpoint

#### Scenario: A completed conversation is continued

- **WHEN** a conversation marked completed is continued
- **THEN** the turn runs
- **AND** the conversation is active again afterwards

#### Scenario: The persona was deactivated mid-conversation

- **WHEN** a conversation is continued after its persona was deactivated
- **THEN** the turn yields a terminal `error` event and no provider call is made

#### Scenario: The system was deleted mid-conversation

- **WHEN** a conversation is continued after its system was soft-deleted
- **THEN** the turn yields a terminal `error` event rather than throwing from inside the event stream

## MODIFIED Requirements

### Requirement: Personas are managed through a service

The package SHALL expose a service that creates, updates, deletes, and lists personas, including the usage rollups and tool listings an admin screen displays.

#### Scenario: Listing personas with lifetime usage

- **WHEN** a host lists personas
- **THEN** each carries its conversation count and aggregated input tokens, output tokens, and cost
- **AND** the usage block is absent for a persona whose conversations have no recorded cost

#### Scenario: Reserved root slugs are rejected

- **WHEN** a persona is saved with a root access path and a slug reserved by the host application
- **THEN** validation fails with a message explaining the conflict

#### Scenario: Listing the tools a persona would expose

- **WHEN** a host asks which tools are available, optionally scoped to a system
- **THEN** it receives the tool names and descriptions the chat loop would expose
- **AND** requesting all tools overrides the system's allow-list

### Requirement: Conversations are searchable through a service

The package SHALL expose a service that filters, searches, and inspects stored conversations, so an operator (a human administrator, not an `AiOperator` record) can find one without querying the models directly.

#### Scenario: Filtering and searching

- **WHEN** a host queries conversations with any combination of feature, status, system, persona, and a free-text search
- **THEN** the search matches conversation title, visitor name and email, the related user's name and email, the related persona's name and slug, and the content of non-system messages

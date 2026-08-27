# ai-management-services Specification

## Purpose

TBD - created by archiving change extract-admin-service-layer. Update Purpose after archive.

## Requirements

### Requirement: AI systems are managed through a service

The package SHALL expose a service that performs every write operation on `AiSystem` records, so a host app can manage systems from its own controllers, commands, or tests without reimplementing the persistence pipeline.

#### Scenario: Creating a system runs the full pipeline

- **WHEN** a host creates a system through the service
- **THEN** `feature_defaults` is separated from the persisted attributes
- **AND** a `custom_system_prompt` with no `system_prompt_id` creates an `AiSystemPrompt` and assigns its id
- **AND** `config`, `credentials`, and `pricing_profile` supplied as JSON strings are decoded before persistence
- **AND** provider capabilities are normalized and hydrated before the record is written
- **AND** feature defaults are synced after the record exists

#### Scenario: Provider and model are immutable after creation

- **WHEN** a host updates an existing system
- **THEN** the stored `provider` and `model` are unchanged regardless of what was submitted
- **AND** capability hydration still resolves against the stored provider, model, and base URL

#### Scenario: A blank base URL falls back to the stored value

- **WHEN** an update omits `base_url` or supplies it blank
- **THEN** the stored base URL is used for capability hydration rather than an empty one

#### Scenario: Deleting a system deactivates its personas

- **WHEN** a system with linked personas is deleted
- **THEN** every linked persona is deactivated rather than deleted
- **AND** the service reports how many were deactivated
- **AND** the system is soft-deleted

#### Scenario: Duplicating a system

- **WHEN** a host duplicates a system
- **THEN** a new record is created with the same attributes and a name suffixed with a copy marker
- **AND** whether feature defaults are copied is an explicit choice, not an accident

### Requirement: A feature has exactly one default system

The package SHALL enforce that a feature key maps to at most one `AiSystem`, and SHALL apply that invariant atomically.

#### Scenario: Claiming a feature already owned by another system

- **WHEN** a system is saved with a feature default currently owned by a different system
- **THEN** the other system's claim on that feature is removed
- **AND** the saving system owns it

#### Scenario: The sync is atomic

- **WHEN** feature defaults are synced
- **THEN** the removal of old claims and the creation of new ones occur in a single transaction, so a failure cannot leave a feature with no default

#### Scenario: Reporting claimed features

- **WHEN** a host asks which features are already claimed, optionally excluding one system
- **THEN** it receives the feature keys, so an editing UI can warn before a claim is taken

### Requirement: Provider models are discoverable with ad-hoc credentials

The package SHALL expose provider model discovery for credentials that do not yet belong to a saved system, so a host can populate a model chooser while creating one.

#### Scenario: Listing models before the system exists

- **WHEN** a host requests models for a provider with an API key and base URL
- **THEN** it receives entries carrying id, display name, loaded state, max context length, and the reasoning/vision/tools capability flags, sorted by name

#### Scenario: A provider that requires a key is given none

- **WHEN** model discovery is requested for a provider requiring an API key and none is supplied
- **THEN** the service fails with an error naming the requirement, before any network call

### Requirement: System prompts are managed through a service

The package SHALL expose a service for reusable system prompt records, including the reference cleanup a delete requires.

#### Scenario: Deleting a prompt clears its references

- **WHEN** a system prompt referenced by one or more systems is deleted
- **THEN** those systems have their prompt reference cleared rather than left pointing at a missing record
- **AND** the service reports how many systems were affected
- **AND** the clearing and the deletion occur in a single transaction

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

#### Scenario: System messages are excluded from user-facing counts

- **WHEN** conversations are listed
- **THEN** message counts exclude messages with the `system` role

#### Scenario: Inspecting a conversation

- **WHEN** a host loads a single conversation
- **THEN** it receives the conversation, its messages in chronological order, and the memories extracted from it ordered by confidence

### Requirement: Memories are managed through a service

The package SHALL expose a service for reviewing, editing, and rebuilding extracted feature memories.

#### Scenario: Listing memories in triage order

- **WHEN** a host lists memories
- **THEN** they are ordered active first, then by descending confidence, then by descending reinforcement count

#### Scenario: Rebuilding a feature's memories

- **WHEN** a host rebuilds memories for a feature
- **THEN** the existing extraction service performs the rebuild

### Requirement: Feature keys are configurable

The package SHALL NOT hardcode host-application feature names in validation.

#### Scenario: A host defines its own feature keys

- **WHEN** a host configures a list of valid feature keys
- **THEN** feature defaults are validated against that list

#### Scenario: No list is configured

- **WHEN** no feature key list is configured
- **THEN** any non-empty string is accepted as a feature key
</content>

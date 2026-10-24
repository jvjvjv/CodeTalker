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

### Requirement: MCP servers are managed through a service

The package SHALL expose a service that performs every write operation on remote MCP server records and triggers catalog syncs. It SHALL publish its validation rules so a host can reuse them in its own form requests.

#### Scenario: Creating a server syncs its catalog

- **WHEN** a host creates a server through the service
- **THEN** the record is stored with its auth configuration encrypted
- **AND** a catalog sync is attempted after the record is committed
- **AND** a failed sync is reported in the result and recorded on the server, rather than thrown or rolling back the record

#### Scenario: Validation rules are reusable

- **WHEN** a host builds a form request for creating or updating a server
- **THEN** it can use the service's published rules, which reject a malformed slug, a non-HTTP(S) URL, an unknown auth type, a non-HTTP transport, and a timeout outside the allowed range

#### Scenario: Auth supplied as a JSON string

- **WHEN** a host supplies the auth configuration as a JSON string
- **THEN** it is decoded before persistence, the same way other JSON attributes are handled

#### Scenario: The slug is immutable after creation

- **WHEN** a host updates an existing server
- **THEN** the stored slug is unchanged regardless of what was submitted, so existing grants keep resolving

#### Scenario: Syncing on demand

- **WHEN** a host asks the service to sync one server, or all enabled servers
- **THEN** each server is synced independently, and the result reports, per server, how many tools were stored, how many are unrepresentable, and any failure

#### Scenario: Deleting a server reports affected systems

- **WHEN** a server is deleted
- **THEN** the server and its catalog are removed from use
- **AND** the service reports how many AI systems had granted one of its tools
- **AND** those systems' allowed-tool lists are left unchanged, and the now-unknown names are ignored

#### Scenario: Listing servers shows sync health

- **WHEN** a host lists servers through the service
- **THEN** each server includes its last sync time, its last sync error, and its tools with their exposed names and availability, and the auth configuration is not included

### Requirement: A serialized system does not carry its secrets

An `AiSystem` SHALL exclude its API key, its credentials, and its web tool policy from array and JSON serialization by default, so a host that passes a system to a response, a view, or a log does not disclose them by accident.

#### Scenario: Serializing a system

- **WHEN** a system holding an API key, credentials, and a web tool policy is converted to an array or to JSON
- **THEN** the result contains none of `api_key`, `credentials`, or `web_tool_policy`
- **AND** its other attributes are present as before

#### Scenario: Serializing a record that includes its system

- **WHEN** a persona or operator is serialized with its system relation loaded
- **THEN** the nested system contains none of `api_key`, `credentials`, or `web_tool_policy`

#### Scenario: Reading a secret directly

- **WHEN** code reads `api_key`, `credentials`, or `web_tool_policy` from a system as an attribute
- **THEN** it receives the decrypted value, exactly as before

#### Scenario: A host opts in to exposing a secret

- **WHEN** a host explicitly makes one of the hidden attributes visible on a system instance before serializing it
- **THEN** that attribute appears in the result
- **AND** the other hidden attributes remain absent

#### Scenario: Duplicating a system keeps its secrets

- **WHEN** a system is duplicated through the service
- **THEN** the copy holds the same API key, credentials, and web tool policy as the original

#### Scenario: An update that omits a secret keeps it

- **WHEN** a system is updated through the service with no `credentials` or `web_tool_policy` key supplied
- **THEN** the stored credentials and web tool policy are unchanged

### Requirement: System credentials are stored encrypted on every supported database

A system's `credentials` SHALL be persisted encrypted in a column that accepts the encrypted value, so storing credentials succeeds regardless of whether the database validates JSON columns.

#### Scenario: Storing credentials

- **WHEN** a system is saved with a credentials map
- **THEN** the write succeeds
- **AND** the value at rest is not the plain credentials
- **AND** reading the attribute back returns the original map

#### Scenario: The credentials column is not a JSON column

- **WHEN** the package's migrations have run
- **THEN** `ai_systems.credentials` is a text column

#### Scenario: Existing credentials survive the upgrade

- **WHEN** a system already holds stored credentials and the migration runs
- **THEN** reading the attribute afterwards returns the same value as before

#### Scenario: A system without credentials

- **WHEN** a system is saved with no credentials
- **THEN** the stored value is null and the attribute reads as null

### Requirement: The system manager validates and translates thinking settings

The system manager SHALL accept a `thinking_level` on create and update, validated against the thinking scale. For one release it SHALL also accept the deprecated boolean `enable_thinking` and translate it when no `thinking_level` is supplied.

#### Scenario: Supplying a level

- **WHEN** a system is created or updated with `thinking_level` set to `low`
- **THEN** the stored level is `low`

#### Scenario: Supplying only the deprecated flag

- **WHEN** a system is created or updated with `enable_thinking` true and no `thinking_level`
- **THEN** the stored level is `medium`
- **AND** with `enable_thinking` false the stored level is `off`

#### Scenario: Supplying both

- **WHEN** a system is submitted with both `thinking_level` and `enable_thinking`
- **THEN** `thinking_level` wins and `enable_thinking` is ignored

#### Scenario: An update that omits thinking keeps it

- **WHEN** a system is updated with neither `thinking_level` nor `enable_thinking` supplied
- **THEN** the stored level is unchanged

#### Scenario: An invalid level

- **WHEN** a system is submitted with a `thinking_level` that is not on the scale
- **THEN** validation fails naming `thinking_level` and nothing is written

### Requirement: The levels a system can honor are discoverable

The system manager SHALL list, for a provider and model, the thinking levels that pair can actually honor, in scale order, together with whether the level controls reasoning effort or only reasoning visibility, so a host can offer only meaningful choices.

#### Scenario: Listing levels for a current model

- **WHEN** a host asks for the levels of a provider and model that supports graded effort
- **THEN** the result lists each supported level in scale order and reports that the level controls effort
- **AND** includes `off` even when the model cannot stop reasoning, because `off` still hides reasoning

#### Scenario: Listing levels where only visibility can be controlled

- **WHEN** a host asks for the levels of a provider or model the package sends no reasoning parameter to
- **THEN** the result lists only `off` and `medium` and reports that the level controls visibility only
- **AND** a host can render that as a show/hide control instead of a level selector

#### Scenario: Listing levels for a voice provider

- **WHEN** a host asks for the levels of a provider that cannot serve the chat role
- **THEN** the result lists no levels

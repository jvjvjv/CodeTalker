## ADDED Requirements

### Requirement: Seeded rows do not block later inserts

Rows the package's migrations seed SHALL leave every supported database able to insert the next row, so a host's first create through a management service succeeds on Postgres as it does on MySQL and SQLite.

#### Scenario: Creating a prompt on Postgres after installing

- **WHEN** the package's migrations, including the default prompt seed, have run on Postgres and a host creates a custom system prompt
- **THEN** the insert succeeds without an identifier collision

### Requirement: A system's API key can be rotated

The system manager SHALL accept an `api_key` on update. A supplied key replaces the stored one; an omitted or blank key keeps it, under the same rule that governs `credentials`.

#### Scenario: Rotating a key

- **WHEN** a system is updated with a new `api_key`
- **THEN** the stored key is the new value and the next provider call uses it

#### Scenario: An update that omits the key

- **WHEN** a system is updated with no `api_key`, or with it blank
- **THEN** the stored key is unchanged

## MODIFIED Requirements

### Requirement: AI systems are managed through a service

The package SHALL expose a service that performs every write operation on `AiSystem` records, so a host app can manage systems from its own controllers, commands, or tests without reimplementing the persistence pipeline. Its validation SHALL require that `config` and `pricing_profile` decode to objects and that `config.provider_options`, when present, is an object; SHALL require a `base_url` for the `openai-compatible` provider; and SHALL accept a `temperature` in the range `0`–`2`. Capability hydration against a local model server SHALL use the system's API key and SHALL NOT block the save when that server is unreachable.

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

- **WHEN** a system with linked personas and operators is deleted
- **THEN** every linked persona and operator is deactivated rather than deleted
- **AND** the system's feature-default claims are removed
- **AND** the service reports how many personas were deactivated
- **AND** the system is soft-deleted
- **AND** all of it occurs in a single transaction

#### Scenario: Duplicating a system

- **WHEN** a host duplicates a system
- **THEN** a new record is created with the same attributes and a name suffixed with a copy marker
- **AND** whether feature defaults are copied is an explicit choice, not an accident

#### Scenario: A config that is not an object

- **WHEN** a system is submitted with `config` set to `"true"`, `"[1,2]"`, or with `config.provider_options` set to a string
- **THEN** validation fails naming the field and nothing is written

#### Scenario: An OpenAI-compatible system without a base URL

- **WHEN** a system with provider `openai-compatible` is submitted without a `base_url`
- **THEN** validation fails naming `base_url`

#### Scenario: A temperature above one

- **WHEN** a system is submitted with `temperature` `1.5`
- **THEN** it is accepted and stored

#### Scenario: The local model server is down during a save

- **WHEN** an LM Studio system is updated while its server is unreachable
- **THEN** the save succeeds and the stored capabilities are left as they were

### Requirement: Provider models are discoverable with ad-hoc credentials

The package SHALL expose provider model discovery for credentials that do not yet belong to a saved system, so a host can populate a model chooser while creating one. The API key SHALL be sent in a request header, never in the request URL.

#### Scenario: Listing models before the system exists

- **WHEN** a host requests models for a provider with an API key and base URL
- **THEN** it receives entries carrying id, display name, loaded state, max context length, and the reasoning/vision/tools capability flags, sorted by name

#### Scenario: A provider that requires a key is given none

- **WHEN** model discovery is requested for a provider requiring an API key and none is supplied
- **THEN** the service fails with an error naming the requirement, before any network call

#### Scenario: Listing Gemini models

- **WHEN** a host requests models for the Gemini provider
- **THEN** the request URL carries no `key` parameter and the key is sent as a header

### Requirement: Personas are managed through a service

The package SHALL expose a service that creates, updates, deletes, and lists personas, including the usage rollups and tool listings an admin screen displays. A persona's or operator's system reference SHALL be validated as an existing, non-deleted, active system serving the chat role.

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

#### Scenario: A persona referencing an unusable system

- **WHEN** a persona or operator is saved referencing a soft-deleted, inactive, or audio-role system
- **THEN** validation fails naming the system field

### Requirement: Memories are managed through a service

The package SHALL expose a service for reviewing, editing, and rebuilding extracted feature memories. Creating a memory SHALL require exactly one identity: a user id or a visitor email.

#### Scenario: Listing memories in triage order

- **WHEN** a host lists memories
- **THEN** they are ordered active first, then by descending confidence, then by descending reinforcement count

#### Scenario: Rebuilding a feature's memories

- **WHEN** a host rebuilds memories for a feature
- **THEN** the existing extraction service performs the rebuild

#### Scenario: Creating a memory without an identity

- **WHEN** a host creates a memory with neither a user id nor a visitor email
- **THEN** validation fails naming the identity requirement

### Requirement: MCP servers are managed through a service

The package SHALL expose a service that performs every write operation on remote MCP server records and triggers catalog syncs. It SHALL publish its validation rules so a host can reuse them in its own form requests. An update that supplies `auth` as null, an empty string, or a JSON `null` SHALL keep the stored auth configuration, so an edit form that round-trips an untouched field cannot clear a secret.

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

#### Scenario: An update that leaves auth blank

- **WHEN** a server is updated with `auth` null, `''`, or the JSON string `null`
- **THEN** the stored auth configuration is unchanged
- **AND** the next sync still authenticates

## ADDED Requirements

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

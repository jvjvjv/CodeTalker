# temporal-information-tool Specification

## Purpose
TBD - created by openspec-sync-specs from change add-temporal-and-http-request-tools. Update Purpose after archive.
## Requirements

### Requirement: The agent can read the current date and time

The package SHALL provide a chat-bot tool named `get-temporal-information` that returns the current instant. It SHALL be discoverable by `ChatBotToolRegistry`, gated in the local chat loop by `AiSystem::allowed_tools`, and registered on `CodeTalkerServer` for the external MCP transport. It SHALL NOT require a conversation, a user identity, or any other `ToolContext` field.

#### Scenario: Current time requested with no timezone

- **WHEN** the tool is invoked with no `timezone` input
- **THEN** it returns a successful structured response describing the current instant
- **AND** the instant is expressed in the application timezone from `config('app.timezone')`
- **AND** the response's `timezone` field names that zone

#### Scenario: Tool is available without a conversation

- **WHEN** the tool is invoked through the external MCP transport, where `ToolContext::conversation` is `null`
- **THEN** it returns the same successful response it returns in the local chat loop
- **AND** no error is raised for the absent conversation

### Requirement: A caller-supplied IANA timezone is honored

The tool SHALL accept an optional `timezone` input holding an IANA timezone identifier, and SHALL express the returned instant in that zone.

#### Scenario: Valid IANA identifier

- **WHEN** the tool is invoked with `timezone` set to `America/New_York`
- **THEN** the returned date, time, weekday, and UTC offset are those of the current instant in `America/New_York`
- **AND** the response's `timezone` field is `America/New_York`

#### Scenario: The underlying instant does not change with the zone

- **WHEN** the tool is invoked twice for the same instant with `timezone` set to `UTC` and then `Asia/Tokyo`
- **THEN** both responses carry the same `unix_timestamp` and the same `utc_iso8601`
- **AND** their `iso8601`, `date`, `time`, and `utc_offset` fields differ according to the zone

### Requirement: A caller-supplied UTC offset is honored

The tool SHALL accept a fixed UTC offset in the `timezone` input in place of an IANA identifier, in the forms `±HH:MM`, `±HHMM`, and `±H`, and SHALL express the returned instant at that offset.

#### Scenario: Offset with a colon

- **WHEN** the tool is invoked with `timezone` set to `-05:00`
- **THEN** the returned instant is expressed five hours behind UTC
- **AND** the response's `utc_offset` field is `-05:00`

#### Scenario: Offset without a colon and a bare-hour offset

- **WHEN** the tool is invoked with `timezone` set to `+0530`, and separately with `+5`
- **THEN** each is accepted
- **AND** each response's `utc_offset` field is normalized to `±HH:MM` form

### Requirement: An unresolvable timezone is refused, not defaulted

The tool SHALL return an error when the `timezone` input can be resolved neither as an IANA identifier nor as a UTC offset. It SHALL NOT silently fall back to the application or UTC timezone, because a confidently-wrong time is worse than a refusal.

#### Scenario: Unrecognized timezone value

- **WHEN** the tool is invoked with `timezone` set to `Pacific/Nowhere` or `EST5EDT7`
- **THEN** it returns an error response
- **AND** the error names the accepted forms — an IANA identifier or a `±HH:MM` UTC offset
- **AND** no time value is returned

### Requirement: The response carries pre-computed calendar parts

The tool's successful response SHALL be a structured payload containing `iso8601`, `utc_iso8601`, `timezone`, `utc_offset`, `unix_timestamp`, `date`, `time`, `day_of_week`, and `human`, so that a model does not have to derive calendar values by parsing a string.

#### Scenario: Successful response shape

- **WHEN** the tool returns successfully
- **THEN** `iso8601` is the instant in the resolved zone in ISO-8601 form
- **AND** `utc_iso8601` is the same instant in UTC
- **AND** `date` is `YYYY-MM-DD`, `time` is `HH:MM:SS`, and `day_of_week` is the English weekday name, all in the resolved zone
- **AND** `human` is a single readable sentence naming the weekday, date, time, and zone

#### Scenario: Time is deterministic under test

- **WHEN** the current time is frozen with `Carbon::setTestNow()` and the tool is invoked
- **THEN** every returned field reflects the frozen instant

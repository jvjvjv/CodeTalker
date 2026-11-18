# provider-error-disclosure Specification

## Purpose
Text produced by a provider, a transport library, or the database when something fails is logged for the operator and never relayed to the browser, to the model, or into a status payload a host renders publicly.

## Requirements

### Requirement: A failed turn reports a fixed message on the wire

The terminal `error` event of a turn SHALL carry a fixed, package-authored `message` chosen by its `reason`. The underlying exception SHALL be logged and recorded against the turn, but its text SHALL NOT appear in any event yielded to the caller, whether the turn is streamed directly or replayed from a dispatched run.

#### Scenario: The provider is unreachable

- **WHEN** the provider connection fails with a message naming the provider's host and port
- **THEN** the yielded `error` event's `message` is the fixed text for `provider_error`
- **AND** the host and port appear in the application log and the turn's failure record only

#### Scenario: The provider rejects the request

- **WHEN** the provider answers with a 4xx body that quotes the API key, the request content, or a configuration hint
- **THEN** none of that body appears in the yielded `error` event

### Requirement: Readiness statuses carry no provider detail

A persona or system readiness status that reports the provider as unavailable SHALL carry a fixed message. The exception that made the provider unavailable SHALL be logged, not embedded in the status.

#### Scenario: Resolving status while the provider is down

- **WHEN** a readiness status is resolved and the provider check throws
- **THEN** the status's `message` is a fixed "provider unavailable" text
- **AND** the exception's text, including any URL it names, is absent from the status payload

### Requirement: Tool and discovery failures are reported generically to their consumer

When a search engine, a model-listing call, or any other outbound call made on behalf of the model or an admin fails at the transport layer, the consumer SHALL receive a generic failure naming only the component that failed. The transport message SHALL be logged with userinfo and sensitive query parameters redacted.

#### Scenario: A search engine times out

- **WHEN** one engine of `search-web` fails with a transport error whose message contains the request URL and its query string
- **THEN** the results given to the model name the engine and say it failed, without the URL or the transport text
- **AND** the logged message does not contain the engine's API key

#### Scenario: Listing models fails

- **WHEN** provider model discovery fails at the transport layer
- **THEN** the error the host receives does not contain the request URL
- **AND** the API key is never part of a request URL in the first place

### Requirement: Database failures do not log the data being written

When a write of provider exchange data or any other large payload fails, the log entry SHALL identify the failure by exception class and code and SHALL NOT include the interpolated statement or its bindings.

#### Scenario: Recording a provider exchange fails

- **WHEN** the insert of a raw provider exchange fails
- **THEN** the warning logged names the exception class and database error code
- **AND** neither the request body nor the response body appears in the log

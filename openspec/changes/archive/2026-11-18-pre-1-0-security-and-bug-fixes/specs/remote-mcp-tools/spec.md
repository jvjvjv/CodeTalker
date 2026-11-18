## ADDED Requirements

### Requirement: Remote tool results are bounded before reaching the model

A remote tool's result SHALL be truncated to a configured byte cap, flagged as truncated, before it is returned to the model, so a large or runaway result cannot exceed the provider's request limit and end the turn.

#### Scenario: A tool returns megabytes of text

- **WHEN** a remote tool returns text content larger than the cap
- **THEN** the model receives the content cut to the cap with a truncation note
- **AND** the turn continues

#### Scenario: Text alongside empty structured content

- **WHEN** a remote tool returns text content and an empty `structuredContent` object
- **THEN** the model receives the text content, not an empty structure

## MODIFIED Requirements

### Requirement: A server's tool catalog is synced and stored
The package SHALL obtain a server's tool list by an explicit sync that stores each tool's remote name, description, and input schema. Chat turns, operator runs, and tool listings SHALL read the stored catalog and SHALL NOT contact the remote server to list its tools. A sync SHALL never throw: a failure at any stage, including the database write, SHALL be recorded as the server's last sync error and reported to the caller. The listing SHALL be bounded to a configured maximum number of tools and pages; a server that exceeds it SHALL be recorded as failed. Stored names SHALL be length-checked before writing.

#### Scenario: Sync stores every page of tools
- **WHEN** a server whose tool listing spans several pages is synced
- **THEN** every tool from every page is stored, and the server's last-synced time is updated

#### Scenario: Tools removed upstream disappear
- **WHEN** a re-sync no longer lists a previously stored tool
- **THEN** that tool is removed from the catalog and is no longer exposed to any system

#### Scenario: A failed sync keeps the previous catalog
- **WHEN** a sync fails partway through because the server is unreachable or returns an invalid listing
- **THEN** the previously stored catalog is unchanged and the server records the failure reason

#### Scenario: Building a turn's tools makes no remote call
- **WHEN** a turn starts for a system granted remote tools, while the remote server is unreachable
- **THEN** the granted remote tools still appear in the turn's tool list, built from the stored catalog, and no network request is made while the list is built

#### Scenario: A never-synced server contributes nothing
- **WHEN** a system's allowed tools name a tool on a server that has never synced
- **THEN** that name is ignored, just as an unknown local tool name is ignored

#### Scenario: A changed definition is observable
- **WHEN** a re-sync finds that a stored tool's description or input schema has changed
- **THEN** the stored definition is updated and an event identifying the server and tool is dispatched

#### Scenario: The catalog write fails
- **WHEN** storing the synced catalog fails with a database error
- **THEN** the sync returns a report carrying the failure, the server records it as its last sync error, and nothing is thrown to the caller

#### Scenario: Two remote names collapse to one exposed name
- **WHEN** a server lists `a.b` and `a/b`, whose exposed names coincide, and a later sync drops `a.b`
- **THEN** the later sync succeeds, `a/b` takes the exposed name, and an event identifying the server and tool is dispatched because the grant now resolves to a different tool

#### Scenario: A server lists more tools than the bound
- **WHEN** a server's listing exceeds the configured maximum number of tools or keeps returning new pages past the page bound
- **THEN** the sync stops, records a failure naming the bound, and the previously stored catalog is unchanged

### Requirement: Unrepresentable schemas are never exposed
A remote tool whose input schema cannot be converted into a tool definition SHALL NOT be offered to the model. The model SHALL NOT be offered a remote tool with an empty or stripped schema in place of its real one. Before conversion, a schema SHALL be checked against a size and reference-count budget, and one that exceeds it SHALL be marked unrepresentable rather than converted, so reference fan-out cannot exhaust memory during a sync or a turn. A representable schema SHALL be converted once per tool list build, not once per use.

#### Scenario: A schema that cannot be converted
- **WHEN** a sync encounters a tool whose input schema cannot be represented
- **THEN** the tool is stored with a reason, reported as unavailable in admin listings, and never offered to the model, even where granted

#### Scenario: A schema that uses root-level definitions
- **WHEN** a remote tool's input schema references definitions declared at its root
- **THEN** the tool is offered with those references resolved, not rejected and not emptied

#### Scenario: A schema whose references fan out
- **WHEN** a sync encounters a small schema whose nested `$ref`s would expand to millions of nodes
- **THEN** the tool is marked unrepresentable with a reason naming the budget
- **AND** the sync completes without exhausting memory

### Requirement: Remote failures become tool errors and the turn completes
A remote tool call that fails SHALL return an error result to the model and SHALL NOT end the turn. This covers an unreachable server, a timeout, a protocol error, a malformed response, and an error result. Each request to the server SHALL be bounded by the server's timeout, which is capped by configuration; the documentation SHALL state that a cold call comprises several requests, each so bounded.

#### Scenario: The server is down during a turn
- **WHEN** the model calls a granted remote tool and the server refuses the connection
- **THEN** the model receives an error result naming the tool, and the turn continues and completes with the model's reply

#### Scenario: The server is slow
- **WHEN** a remote call exceeds its timeout
- **THEN** the call is abandoned at the timeout, and the model receives an error result

#### Scenario: A configured timeout above the cap
- **WHEN** a server's timeout exceeds the configured maximum
- **THEN** the configured maximum is used

#### Scenario: The server returns garbage
- **WHEN** a remote call returns a response that is not a valid tool result
- **THEN** the model receives an error result, and the turn completes

#### Scenario: The remote tool reports an error
- **WHEN** a remote tool returns a result flagged as an error
- **THEN** the model receives that error text as an error result

#### Scenario: Successful results reach the model
- **WHEN** a remote tool returns structured content
- **THEN** the model receives the structured content; otherwise it receives the text content

### Requirement: A failing server is not retried within a turn
After a remote server fails to connect, times out, or rejects the session handshake, later calls to any of that server's tools within the same turn SHALL fail immediately without a network request. Tool-level error results SHALL NOT trigger this.

#### Scenario: Repeated calls after a timeout
- **WHEN** a call to `mdn__search` times out and the model then calls `mdn__search` or another `mdn__` tool in the same turn
- **THEN** the later calls return an error result immediately, without waiting for another timeout

#### Scenario: A tool error does not trip the breaker
- **WHEN** a remote tool returns an error result for invalid arguments
- **THEN** later calls to that server in the same turn are still attempted

#### Scenario: The next turn tries again
- **WHEN** a server failed during one turn and a later turn calls one of its tools
- **THEN** the later turn attempts the call

#### Scenario: The handshake is rejected
- **WHEN** the server answers the session handshake with a protocol error
- **THEN** the model receives an error result describing the server as unavailable, not as having rejected the tool's arguments
- **AND** later calls to that server in the same turn make no request

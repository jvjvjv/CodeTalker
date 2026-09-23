# remote-mcp-tools Specification

## Purpose
Lets an AiSystem use tools hosted on external MCP servers. Each remote tool appears as its own individually grantable tool with its real input schema, and a remote failure never prevents a turn from completing.

## Requirements

### Requirement: Remote MCP servers are defined as stored records
The package SHALL let a host define external MCP servers as stored records. Each record has a unique slug, an HTTP(S) URL, an auth configuration, an optional timeout, and an enabled flag. Auth configuration SHALL be encrypted at rest. Only the HTTP transport SHALL be accepted.

#### Scenario: Defining an unauthenticated server
- **WHEN** a host defines a server with slug `mdn`, an HTTPS URL, and auth type `none`
- **THEN** the server is stored and is available to be synced

#### Scenario: Credentials are not stored in plain text
- **WHEN** a server is defined with a bearer token, static headers, or client credentials
- **THEN** the stored auth value is encrypted, and the model never sees it in tool definitions or tool results

#### Scenario: A stdio transport is refused
- **WHEN** a host attempts to define a server with a non-HTTP transport or a command line
- **THEN** the definition is rejected by validation

### Requirement: A server's tool catalog is synced and stored
The package SHALL obtain a server's tool list by an explicit sync that stores each tool's remote name, description, and input schema. Chat turns, operator runs, and tool listings SHALL read the stored catalog and SHALL NOT contact the remote server to list its tools.

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

### Requirement: Remote tools are exposed under namespaced names
Each remote tool SHALL be exposed under the name `{server-slug}__{remote-name}`, using only the characters `A–Z`, `a–z`, `0–9`, `_` and `-`, and at most 64 characters. The same exposed name SHALL be used as the tool name sent to the model, the name used to grant the tool, and the name used to dispatch it. The name SHALL be deterministic across syncs.

#### Scenario: Two servers expose same-named tools
- **WHEN** servers `mdn` and `docs` both list a tool named `search`
- **THEN** they are exposed as `mdn__search` and `docs__search`, and each can be granted independently

#### Scenario: A remote tool never shadows a package tool
- **WHEN** a remote server lists a tool named `search-web`
- **THEN** it is exposed as `{slug}__search-web`, and the package's `search-web` tool is unaffected

#### Scenario: Characters outside the allowed set are replaced
- **WHEN** a remote tool is named `docs.search/v2`
- **THEN** its exposed name replaces each disallowed character with `_`

#### Scenario: Overlong names are shortened deterministically
- **WHEN** a slug and a remote name together exceed 64 characters
- **THEN** the exposed name is at most 64 characters, stays unique to that tool, and is identical on every re-sync

#### Scenario: A local tool with an identical name wins
- **WHEN** a host-registered local tool has the same name as a remote tool's exposed name
- **THEN** the local tool is used, the remote tool is left out, and a warning is logged

### Requirement: Only granted remote tools reach the model
A remote tool SHALL be offered to the model only when its exact exposed name appears in the governing `allowed_tools` list (the AiSystem's, or the operator's override), its server is enabled, and remote MCP support is enabled in configuration. Wildcard grants SHALL NOT be supported.

#### Scenario: Granting one tool from a server
- **WHEN** an AiSystem's allowed tools include `mdn__search` but no other `mdn__` tool
- **THEN** a turn under that system offers `mdn__search` with its synced description and input schema, and offers no other tool from `mdn`

#### Scenario: Tools listed on a server are not granted automatically
- **WHEN** a re-sync adds a new tool to a server that a system already uses
- **THEN** the new tool is not offered to that system until its exposed name is added to allowed tools

#### Scenario: A disabled server withdraws its tools
- **WHEN** a server is disabled or deleted
- **THEN** none of its tools are offered to any system, even where they are granted

#### Scenario: The feature switch withdraws all remote tools
- **WHEN** remote MCP support is disabled in configuration
- **THEN** no remote tool is offered to any system, and local tools are unaffected

#### Scenario: Admin tool listings include synced remote tools
- **WHEN** a host requests the full list of available tools for a persona or operator
- **THEN** every exposable remote tool from every enabled server is included, by exposed name, and the list is built without a remote call

#### Scenario: A persona with tools disabled gets no remote tools
- **WHEN** a persona has tools disabled
- **THEN** it is offered no remote tools, regardless of its system's grants

### Requirement: Unrepresentable schemas are never exposed
A remote tool whose input schema cannot be converted into a tool definition SHALL NOT be offered to the model. The model SHALL NOT be offered a remote tool with an empty or stripped schema in place of its real one.

#### Scenario: A schema that cannot be converted
- **WHEN** a sync encounters a tool whose input schema cannot be represented
- **THEN** the tool is stored with a reason, reported as unavailable in admin listings, and never offered to the model, even where granted

#### Scenario: A schema that uses root-level definitions
- **WHEN** a remote tool's input schema references definitions declared at its root
- **THEN** the tool is offered with those references resolved, not rejected and not emptied

### Requirement: Remote failures become tool errors and the turn completes
A remote tool call that fails SHALL return an error result to the model and SHALL NOT end the turn. This covers an unreachable server, a timeout, a protocol error, a malformed response, and an error result. Each call SHALL be bounded by the server's timeout, which is capped by configuration.

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
After a remote server fails to connect or times out, later calls to any of that server's tools within the same turn SHALL fail immediately without a network request. Tool-level error results SHALL NOT trigger this.

#### Scenario: Repeated calls after a timeout
- **WHEN** a call to `mdn__search` times out and the model then calls `mdn__search` or another `mdn__` tool in the same turn
- **THEN** the later calls return an error result immediately, without waiting for another timeout

#### Scenario: A tool error does not trip the breaker
- **WHEN** a remote tool returns an error result for invalid arguments
- **THEN** later calls to that server in the same turn are still attempted

#### Scenario: The next turn tries again
- **WHEN** a server failed during one turn and a later turn calls one of its tools
- **THEN** the later turn attempts the call

### Requirement: Remote servers do not receive the conversation's identity
Remote tool calls SHALL carry only the model-supplied arguments and the server's own configured credentials. The conversation's user id, visitor name, and visitor email SHALL NOT be sent to a remote server by the package.

#### Scenario: A signed-in user's turn
- **WHEN** a signed-in user's turn calls a remote tool
- **THEN** the request carries the model's arguments and the server's credentials, and no user or visitor identity added by the package

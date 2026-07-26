## ADDED Requirements

### Requirement: The Inertia prop contract is documented and versioned

The README SHALL document every prop the package passes to each chat page, and SHALL state that these props follow the package's semantic version — a rename, removal, or type change is a breaking change.

#### Scenario: A host developer builds the chat page

- **WHEN** a developer reads the README's Frontend Integration section
- **THEN** they find the complete prop list for `ai/ChatBot` — `bot` (with `name`, `description`, `require_visitor_identity`, `total_cost_usd`), `messages`, `history`, `messageUrl`, `resetUrl`, `switchUrl`, `statusUrl`, `warmupUrl`, `chatUrl`, `chatUrlBase`, `showIdentityForm` — and the additional `chatHash` present only on the hash-linked page
- **AND** the complete prop list for `ai/ChatBotsIndex`, including the per-conversation `title`, `updated_at`, `updated_at_human`, and `is_stale`
- **AND** the shape of each `messages` entry: `role`, `content`, `reasoning_content`, `blocks`

#### Scenario: The contract is bound to semver

- **WHEN** the README describes the props or the stream
- **THEN** it states that both are public API covered by the package's version, and that changes to them are released as breaking

### Requirement: The SSE stream contract is documented

The README SHALL document every event the chat message endpoint streams, its payload shape, the stream terminator, and the response header carrying the chat hash.

#### Scenario: A host developer implements a stream consumer

- **WHEN** a developer reads the streaming section
- **THEN** they find each event type and payload: `status` (`phase` of `request_received` or `model_loading`, plus `message`), `message_start`, `content_block_delta` (`delta.text`), `reasoning_block_delta` (`delta.reasoning`), `message_delta` (`delta.stop_reason` plus `usage`), `message_stop`, and `error` (`message`, and a `reason` of `max_stream_duration` or `provider_error`)
- **AND** that every frame is sent as `data: <json>\n\n` and a successful turn ends with the literal `data: [DONE]\n\n`
- **AND** that the response carries the conversation's hash in the `X-Chat-Hash` header

#### Scenario: Undocumented events are not part of the contract

- **WHEN** a host consumer branches on an event type
- **THEN** only the documented types are emitted by the package, so handling any other type is unnecessary

### Requirement: TypeScript declarations are publishable

The package SHALL ship TypeScript declarations for both contracts, publishable into a host app, so a chat UI is typechecked against the package rather than against assumptions.

#### Scenario: Publishing the types

- **WHEN** a developer runs `php artisan vendor:publish --tag=code-talker-types`
- **THEN** declaration files describing the chat page props, the index page props, and the SSE event union are written into the host app
- **AND** the declarations require no runtime dependency and no framework

#### Scenario: The event union is exhaustive

- **WHEN** a consumer switches over the published SSE event union
- **THEN** the union covers exactly the documented event types, so an exhaustiveness check compiles without a catch-all case

### Requirement: A framework-agnostic stream client is publishable

The package SHALL ship a dependency-free TypeScript client that consumes the chat message endpoint and reports progress through typed callbacks, publishable into a host app and owned by it thereafter.

#### Scenario: Publishing and using the client

- **WHEN** a developer runs `php artisan vendor:publish --tag=code-talker-client` and calls the published function with a message URL and payload
- **THEN** it POSTs the message, parses the SSE response, and invokes `onStatus`, `onText`, `onReasoning`, `onDone`, and `onError` as the corresponding frames arrive
- **AND** it reports the `X-Chat-Hash` value once the response headers are available
- **AND** it resolves when the `[DONE]` sentinel is received

#### Scenario: The caller can cancel a turn

- **WHEN** the caller invokes the abort handle the client returns
- **THEN** the request is aborted and no further callbacks fire

#### Scenario: A streamed error is surfaced, not thrown

- **WHEN** the stream carries an `error` frame
- **THEN** `onError` receives its message and reason
- **AND** partial text already delivered through `onText` is not discarded

#### Scenario: No framework is imposed

- **WHEN** the client is published into any host app
- **THEN** it imports nothing beyond standard browser APIs, and works irrespective of the host's UI framework

### Requirement: Rendered component names are configurable

The Inertia component each chat page renders SHALL be read from configuration, so a host can point the package at its own component paths without subclassing a controller.

#### Scenario: Default configuration preserves current behavior

- **WHEN** a host has not configured component names
- **THEN** the chat page renders `ai/ChatBot` and the index renders `ai/ChatBotsIndex`

#### Scenario: A host overrides the component names

- **WHEN** `code-talker.inertia.components.chat_bot` is set to a different component path
- **THEN** the chat page and the hash-linked page both render that component
- **AND** the props passed to it are unchanged

### Requirement: Shipped TypeScript is typechecked

The package's TypeScript SHALL be verified in CI rather than assumed correct, without making Node a requirement for host apps.

#### Scenario: Typecheck runs in CI

- **WHEN** the test workflow runs
- **THEN** a job typechecks the shipped declarations and client and fails the build on a type error

#### Scenario: Host apps need no Node toolchain

- **WHEN** a host app installs the package via Composer
- **THEN** no Node dependency, build step, or `package.json` merge is required of them

## MODIFIED Requirements

### Requirement: A framework-agnostic stream client is publishable

The package SHALL ship a dependency-free TypeScript client that consumes the chat message endpoint and reports progress through typed callbacks, publishable into a host app and owned by it thereafter. It SHALL honour an `AbortSignal` that is already aborted when passed, and SHALL release any listener it attached to a caller's signal once the turn ends.

#### Scenario: Publishing and using the client

- **WHEN** a developer runs `php artisan vendor:publish --tag=code-talker-client` and calls the published function with a message URL and payload
- **THEN** it POSTs the message, parses the SSE response, and invokes `onStatus`, `onText`, `onReasoning`, `onToolProgress`, `onPageReload`, `onDone`, and `onError` as the corresponding frames arrive
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

#### Scenario: Tool activity and reload signals reach the client

- **WHEN** the stream carries a `tool_use_progress` or `page_reload` frame
- **THEN** the client invokes `onToolProgress` or `onPageReload` respectively, rather than falling through to the unrecognized-type fallback

#### Scenario: A signal aborted before the call

- **WHEN** the caller passes an `AbortSignal` that is already aborted
- **THEN** no request is sent and the returned promise settles as an abort

#### Scenario: A long-lived signal is reused across turns

- **WHEN** the caller passes the same `AbortSignal` to many sequential turns that each finish normally
- **THEN** the signal accumulates no listeners from finished turns

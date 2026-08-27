## MODIFIED Requirements

### Requirement: The SSE stream contract is documented

The README SHALL document every event the chat message endpoint streams, its payload shape, the stream terminator, and the response header carrying the chat hash.

#### Scenario: A host developer implements a stream consumer

- **WHEN** a developer reads the streaming section
- **THEN** they find each event type and payload: `status` (`phase` of `request_received` or `model_loading`, plus `message`), `message_start`, `content_block_delta` (`delta.text`), `reasoning_block_delta` (`delta.reasoning`), `message_delta` (`delta.stop_reason` plus `usage`), `message_stop`, `tool_use_progress` (`text`, always `""`; `tools`, one tool name per event; optionally `input`/`output`/`successful` when the host enables tool payloads), `page_reload` (no payload), and `error` (`message`, and a `reason` of `max_stream_duration` or `provider_error`)
- **AND** that every frame is sent as `data: <json>\n\n` and a successful turn ends with the literal `data: [DONE]\n\n`
- **AND** that the response carries the conversation's hash in the `X-Chat-Hash` header

#### Scenario: Undocumented events are not part of the contract

- **WHEN** a host consumer branches on an event type
- **THEN** only the documented types are emitted by the package, so handling any other type is unnecessary

#### Scenario: A tool result signals that server state changed

- **WHEN** a tool's structured result carries `_page_reload: true`
- **THEN** the stream emits a `page_reload` event for that tool result, in addition to any `tool_use_progress` event for the same tool call
- **AND** a tool result without that key, or with any other value for it, does not trigger the event

### Requirement: A framework-agnostic stream client is publishable

The package SHALL ship a dependency-free TypeScript client that consumes the chat message endpoint and reports progress through typed callbacks, publishable into a host app and owned by it thereafter.

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

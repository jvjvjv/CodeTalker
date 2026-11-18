## Why

A 1.0.0 release freezes the public surface, so defects that are cheap to fix now become compatibility promises later. A review of the outbound web tools, remote MCP, the chat turn and memory system, and the management and provider plumbing found one install-blocking bug, error paths that relayed more detail than a consumer should see, memory scoping that was looser than documented, and a set of correctness bugs. This change fixes them together so 1.0.0 ships without them.

## What Changes

### Install

- Ship the migration that adds `ai_conversations.uuid`, with a backfill. The model writes the column and `generateChatHash()` hashes it, but no package migration created it, so `startConversation()` failed on a host that followed the README.
- Advance the seeded system-prompt id sequence on Postgres so the first `AiSystemPrompt::create()` does not collide on id 1.

### Error reporting

- Turn errors, readiness statuses, `search-web` engine failures, and provider model listing report fixed, package-authored messages. The exception is logged.
- Provider keys are sent in headers, never in a URL. URLs written to logs have userinfo and key-like query values masked.
- A failed raw-exchange insert logs the exception class and code only.
- **BREAKING**: `error.message` on the wire is a fixed string per `reason`. Hosts that displayed the provider's text will see the generic one; the detail is in the log and on the turn's failure record.

### Web tools (`HostGate` / `WebFetcher`)

- A URL carrying userinfo (`user:pass@host`) is refused.
- An unresolvable host is still refused, and is never pinned to an address.
- The private-address check covers more reserved ranges: `100.64.0.0/10`, `192.0.0.0/24`, `198.18.0.0/15`, `224.0.0.0/4`, `64:ff9b::/96`, `2002::/16`, `fec0::/10`. `localhost` resolves like any other name.
- **BREAKING**: API-key headers (`x-api-key`, `api-key`, `x-auth-token`, `x-access-token`, `x-goog-api-key`, `x-amz-security-token`, `x-session-token`) are credential headers, subject to the same rules as `Authorization`. A redirect that changes origin forwards only a benign header allow-list.
- **BREAKING**: configured credentials attach only over `https` or to a loopback address.
- **BREAKING**: over the external MCP transport, `request_policy.allow_private_hosts: true` is refused. Private hosts are reachable only from a conversation.
- `max_body_length` is enforced while downloading, measured after decompression.
- Malformed header lines are dropped and reported instead of ending the turn.
- The `GET` a 301/302/303 produces is not checked against `allowed_methods`.
- `http-request` returns the decoded body of a 4xx/5xx response alongside the error.

### Memory scoping

- A memory belongs to exactly one identity. A memory written for a user stores no visitor email, and a visitor email never matches a user's memories.
- A conversation with neither a user nor a visitor email, and every operator run, is not analysed.
- Memories injected into a prompt are selected by the conversation's identity, passed by the caller, never by the ambient session.
- `AiMemoryManager::create()` requires exactly one identity.
- The README states that a visitor email is unverified.

### Remote MCP

- `RemoteToolCatalogSync::sync()` never throws, including when the catalog write fails. A tool name that passes to a different remote tool is reassigned cleanly and announced with `RemoteMcpToolDefinitionChanged`. Stored names are length-checked.
- `AiMcpServerManager::update()` keeps the stored `auth` when it arrives null, `''`, or JSON `null`.
- Tool listings, result sizes, and input-schema size are bounded. Each schema is converted once per registry build.
- A rejected handshake trips the per-turn breaker. An empty `structuredContent` no longer hides the text content.
- `mcp.web.middleware` falls back to `['auth:sanctum']` when the key is absent from a cached config.
- Discovered tools receive the conversation's `ToolContext` whatever their constructor parameter is named.

### Conversation turns

- `continueConversation()` re-activates a `Completed` conversation. A persona that was deactivated, or a system that was deleted, ends the turn with a terminal `error` event instead of an exception.
- Replay drops tool calls whose result was never recorded.
- A continuation after a `length` stop appends only that attempt's text.
- Turn numbers are ordered numerically.
- `ai:complete-idle-conversations` pages by id and treats a negative timeout as 0.
- Visitor name and email are trimmed, stripped of control characters, and length-checked.

### Management

- `AiSystemManager::update()` accepts a new `api_key`; a blank one keeps the stored key.
- Deleting a system also deactivates its operators and releases its feature defaults, in one transaction.
- `config`, `pricing_profile`, and `config.provider_options` must be objects; `openai-compatible` needs a `base_url`; `temperature` accepts 0–2.
- A persona's or operator's system must be active, not deleted, and chat-role.
- LM Studio capability hydration sends the API key and does not block a save when the server is down.
- The stream client honours an already-aborted `AbortSignal` and removes its listener when the turn ends.

## Capabilities

### New Capabilities

- `provider-error-disclosure`: text from a provider, transport, or database failure is logged, never relayed to the browser, the model, or a public status payload.
- `memory-scoping`: which identity a memory belongs to, which conversations are analysed, and which identity selects injected memories.

### Modified Capabilities

- `chat-turn-library`: a fresh install can open a conversation; continuing a completed conversation re-activates it; an unusable persona or system ends the turn with a terminal error; visitor fields are sanitised.
- `http-request-tool`: userinfo refused; unresolvable hosts never pinned; more reserved ranges; broader credential headers and an origin-scoped redirect rule; credentials only over TLS or loopback; header validation; redirect-induced `GET` exempt from the method check; error bodies returned; `allow_private_hosts` refused on the MCP transport.
- `web-fetcher-length-limits`: the raw body cap is enforced while downloading.
- `remote-mcp-tools`: sync never throws and bounds the listing; schema size budget; result size cap; handshake errors trip the breaker; name reassignment is announced.
- `native-conversation-continuation`: unmatched tool calls are dropped on replay.
- `ai-management-services`: API key rotation; transactional delete covering operators and feature defaults; stricter JSON-shape, system-reference, `base_url`, and temperature validation; MCP server `auth` kept on blank; memory creation requires an identity; model listing never puts the key in a URL; the prompt seed is Postgres-safe.
- `frontend-integration-contract`: the stream client honours a pre-aborted signal and releases its listener.

## Impact

- **Migrations**: two new migrations: `ai_conversations.uuid` with a backfill (which also clears `visitor_email` on user-owned memories), and a Postgres-only sequence advance for `ai_system_prompts`.
- **Wire format**: event shape unchanged. `error.message` text changes and `reason` gains `persona_unavailable` and `system_unavailable`. README "Turn events" and `code-talker.d.ts` updated.
- **Behavior changes hosts may notice** (marked BREAKING above): generic error text, credential headers gated more broadly, credentials withheld over plain HTTP, `allow_private_hosts` refused on MCP, `AiMcpServerManager::update(auth: null)` no longer clears.
- **Code**: `Services/Web/`, `Services/Mcp/Tools/ChatBot/`, `Services/Mcp/Remote/`, `Services/Management/`, `Services/ChatBot/Conversation/`, `Services/Conversation/`, `AiMemoryService`, `AiModelReadinessService`, `ProviderModelsClient`, `RawExchangeRecorder`, `CodeTalkerServiceProvider`, `CompleteIdleConversationsCommand`, `resources/js/code-talker-stream.ts`, README.
- **Not in scope**: making the model-declared `request_policy` a boundary, verified visitor identity, and the CHANGELOG entry, which is written in the release commit.

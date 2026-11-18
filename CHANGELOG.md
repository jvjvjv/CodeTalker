# Changelog

## [0.20.0] — 2026-11-18

Adds the migration a fresh install needs to open a conversation, and tightens error reporting, memory scoping, the web tools, remote MCP syncing and management validation.

### Breaking Changes
- Two new migrations ship with this release: `ai_conversations.uuid` is added and filled for existing rows, which also clears `visitor_email` on memories that belong to a user, and the seeded system-prompt id sequence is advanced on Postgres. Re-publish migrations and run them.
- A terminal `error` event's `message` is now a fixed string for its `reason`, never the provider's or the exception's text. The detail is logged and kept on `AiInteractionLog` and `AiTurnRun.error_message`.
- `reason` gains `persona_unavailable` and `system_unavailable`: `continueConversation()` ends the turn with a terminal `error` when the persona has been deactivated or its system deleted, where it previously threw. Handle both in exhaustive matches.
- `x-api-key`, `api-key`, `x-auth-token`, `x-access-token`, `x-goog-api-key`, `x-amz-security-token` and `x-session-token` are now credential headers, under the same rules as `Authorization`. A redirect to a different origin (scheme, host or port) carries only a fixed set of benign headers.
- Credentials configured on an `AiSystem` attach only to `https` URLs and loopback addresses. An endpoint that needs them over plain `http` must move to `https`.
- `http-request` and `fetch-web-page` refuse a URL carrying a username or password, and refuse `100.64.0.0/10`, `192.0.0.0/24`, `198.18.0.0/15`, `224.0.0.0/4`, `64:ff9b::/96`, `2002::/16` and `fec0::/10` unless the policy allows private hosts.
- On the external MCP transport, `request_policy.allow_private_hosts: true` is refused. Private hosts are reachable only from a conversation.
- `AiMcpServerManager::update()` keeps the stored `auth` when it is submitted as `null`, an empty string or a JSON `null`. To remove a server's auth, send `{"type":"none"}`.
- `AiMemoryManager::create()` requires exactly one of `user_id` and `visitor_email`. A memory written for a user stores no visitor email, and a visitor email never matches a user's memories.
- A conversation with neither a user nor a visitor email, and every operator run, is no longer analysed for memories.
- `startConversation()` trims the visitor name and email and strips control characters from them, and throws `InvalidArgumentException` naming the field when one is longer than 255 characters or the email is malformed.
- `AiSystemManager`, `AiPersonaManager` and `AiOperatorManager` validate more strictly: `config`, `pricing_profile` and `config.provider_options` must be JSON objects, `openai-compatible` requires `base_url` on create, `temperature` accepts 0 to 2, and a persona's or operator's system must be active, not deleted and chat-role. Check stored records before their next save.

### New Features
- `AiSystemManager::update()` accepts a new `api_key`, so a system's key can be rotated. A blank one keeps the stored key.
- `http-request` returns the decoded body of a 4xx or 5xx response alongside its status.
- Added `remote_mcp.max_tools` (default 500) and `remote_mcp.max_result_bytes` (default 100 000). A sync stops at the tool limit and keeps the previous catalog, and a longer tool result is cut to the byte limit with a note.

### Bug Fixes
- `startConversation()` no longer fails on a fresh install for lack of the `ai_conversations.uuid` column.
- The first `AiSystemPrompt::create()` on Postgres no longer collides with the seeded prompt's id.
- Memories injected into the system prompt are selected by the conversation's user or visitor email, not by the user signed in to the current request.
- Deleting an `AiSystem` also deactivates its operators and removes its feature-default claims, in one transaction, so a feature no longer points at a deleted system.
- Readiness statuses and `search-web` engine failures report a fixed message and log the detail. Gemini model listing sends the key in a header, and URLs written to logs have credentials masked.
- A failed raw-exchange insert logs the exception class and code, not the data being written.
- `max_body_length` is enforced while a response downloads, measured after decompression.
- A malformed header line passed to `http-request` is dropped and reported in `stripped_headers` instead of ending the turn.
- The `GET` that a 301, 302 or 303 redirect produces is no longer refused by a policy that allows only other methods.
- `RemoteToolCatalogSync::sync()` no longer throws when writing the catalog fails; it records `last_sync_error` and keeps the previous catalog.
- A remote tool name that passes to a different remote tool is reassigned without a sync error and announced with `RemoteMcpToolDefinitionChanged`. A remote name longer than 255 characters marks the tool unrepresentable, and a long title is truncated.
- An oversized or deeply nested remote input schema marks the tool unrepresentable instead of stalling the turn, and each schema is converted once per registry build.
- A server that rejects the `initialize` handshake is not called again in the same turn, and an empty `structuredContent` no longer hides a result's text.
- `mcp.web.middleware` falls back to `['auth:sanctum']` when a cached config has no such key.
- Discovered tools receive the conversation's `ToolContext` in any constructor parameter of that type, whatever its name.
- Continuing a `Completed` conversation re-activates it.
- Replayed history drops a tool call whose result was never recorded, so a turn interrupted between tool results no longer breaks every later turn.
- A continuation after a `length` stop sends only that attempt's text, not every earlier attempt's again.
- Turn numbers above 9 are ordered numerically.
- `ai:complete-idle-conversations` completes every idle conversation in a large backlog, and treats a negative `idle_timeout_minutes` as 0.
- LM Studio capability hydration sends the system's API key, and a save succeeds while the server is unreachable, keeping the stored capabilities.
- `scan-memories` ignores a non-string `topics` entry instead of failing.
- The stream client stops at once when handed an already-aborted `AbortSignal`, and removes its abort listener when the turn ends.

### Known Issues
- A visitor email is whatever the visitor typed, and is not verified. Where visitor-scoped memories should stay private, require authentication.
- The remote MCP timeout bounds each HTTP request, not a whole call. A first call on a cold session makes several requests, so a slow server can hold a turn for several multiples of the timeout.

## [0.19.0] — 2026-10-24

`AiSystem` records now set a graded thinking level instead of switching thinking on or off, and each provider receives the reasoning request its model accepts.

### Breaking Changes
- A new migration adds `ai_systems.thinking_level` and fills it from `enable_thinking`: `true` becomes `medium`, `false` becomes `off`, and an unset flag leaves the level unset. Re-publish migrations and run them.
- `enable_thinking` is deprecated in favor of `thinking_level`. `AiSystemManager` still accepts it, translating it when no `thinking_level` is submitted, and it will be removed in a later release.
- Reasoning now reaches turn events and the stored reply only when a system's thinking level is above `off`. A system with no level, which previously showed any reasoning Anthropic, OpenAI or Gemini returned, now shows none.
- An Anthropic system with thinking enabled now sends adaptive thinking at `medium` effort on Claude 4.6 and later, and a 4096-token budget on Claude 3.7 through 4.5, instead of a fixed 1024-token budget. Set `thinking_level` to `low` to spend less.
- `AgentFactory::providerOptionsFor()` now takes the system and the resolved thinking options. A subclass that overrides it must update its signature.

### New Features
- Added `AiSystem::thinking_level` and the `ThinkingLevel` enum (`off`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max`). Leaving it unset sends no reasoning parameter, so the provider's default applies.
- The level is sent as Anthropic adaptive thinking and effort (or a thinking budget on older models), OpenAI and xAI `reasoning.effort`, Gemini `thinkingConfig`, and LM Studio's `reasoning` field. A level the model does not offer is sent as the nearest lower one it does.
- OpenAI and Gemini systems now stream their reasoning when the level is above `off`.
- Added `AiSystemManager::thinkingLevels($provider, $model, $modelCapabilities)`, which lists the levels a provider and model can honor and whether the level controls effort or only whether reasoning is shown, for building a level selector.
- Added `AiSystem::thinkingLevel()`, which falls back to `enable_thinking` when no level is stored or the column has not been migrated yet.

### Bug Fixes
- An Anthropic system with thinking enabled no longer fails on Claude Opus 4.7 and later, which reject a fixed thinking budget.

## [0.18.0] — 2026-10-13

Tightens how an `AiSystem`'s `credentials`, `web_tool_policy` and `role` are validated and enforced.

### Breaking Changes
- `AiSystemManager::update()` now keeps the stored `credentials` or `web_tool_policy` when either is submitted as `null`, an empty string, or a JSON `null`, where it previously cleared it. To clear one, send an empty object (`'{}'`). This replaces the 0.17.0 advice that an empty submission clears the policy.
- `credentials` and `web_tool_policy` must be JSON objects; a number, string, boolean or list is rejected by validation on create and update.
- `web_tool_policy` accepts only the keys `allowed_domains` and `credentials`. Any other key is a validation error naming it, where it was previously stored and ignored.
- `AgentFactory::forSystem()` throws for a system whose role is `audio` or `transcription`, where it previously built the agent and failed at the provider call.

### Bug Fixes
- A system loaded without its `role` — selected without the column, or read before the `role` migration has run — is now refused by `AgentFactory::forFeature()` and the audio services with an error that names the system and says what to do, instead of a property-read error.

## [0.17.0] — 2026-10-13

`AiSystem` records can now serve speech-to-text and text-to-speech, and a serialized system no longer carries its secrets.

### Breaking Changes
- `AiSystem` no longer includes `api_key`, `credentials` or `web_tool_policy` in `toArray()` or `toJson()`, including when serialized as a relation. Read them as attributes, or call `makeVisible()` on the instance to include one. An edit form that prefilled `web_tool_policy` from the serialized system must read the attribute instead: submitting the field empty clears the stored policy, and a system with no policy is unrestricted.
- Two new migrations ship with this release: `ai_systems.credentials` changes from `json` to `text`, and `ai_systems.role` is added with a default of `chat`. Re-publish migrations and run them.

### New Features
- Added `Services/Audio/SpeechService` and `Services/Audio/TranscriptionService`, each with `forSystem()` and `forFeature()`, for text-to-speech and speech-to-text using the credentials on an `AiSystem` record. They return laravel/ai's `AudioResponse` and `TranscriptionResponse`.
- Added `AiSystem::role` (`chat`, `audio`, `transcription`), the `AiSystemRole` enum and an `AiSystem::role()` query scope. A system serves one role, and `AgentFactory::forFeature()` refuses a feature whose default is a voice system.
- Added the `elevenlabs` provider, for the `audio` and `transcription` roles, with a `providers.elevenlabs.base_url` config key (`ELEVENLABS_BASE_URL`).
- `AiSystemManager` validates `role` and rejects a provider that cannot serve it, naming both. `model` and `max_tokens` are optional for voice roles; a blank model uses the provider's default.
- Added `AiSystemManager::providersFor($role)` and `AiProvider::supportsRole()`. `AiPersonaManager::availableSystems()` now lists chat systems only.

### Bug Fixes
- Saving `credentials` on an `AiSystem` no longer fails on databases that validate JSON columns.

### Known Issues
- Speech and transcription calls are not included in usage or cost totals and are not written to `AiLlmMessage`.
- `lm-studio` and `openai-compatible` cannot serve the `audio` or `transcription` roles; voice needs a separate system on `openai`, `gemini` or `elevenlabs`.
- Voice calls are captured as raw exchanges only when their provider is added to `raw_exchanges.providers`, which defaults to `lm-studio`.

## [0.16.1] — 2026-09-28

Documentation-only release: the README's upgrade guide now covers every release through 0.16.0.

### Bug Fixes
- The "Migrating between versions" table in the README now includes rows for 0.14.1, 0.15.0 and 0.16.0, listing the migrations each requires and the host-app changes 0.15.0 calls for.

## [0.16.0] — 2026-09-23

An `AiSystem` can now use tools hosted on external MCP servers, granted by name
alongside the package's own tools.

### Breaking Changes
- Two new migrations (`ai_mcp_servers`, `ai_mcp_server_tools`) ship with this release. Re-publish migrations and run them, even if you do not use remote MCP servers — `ai:sync-mcp-servers` is scheduled by default and expects the tables.

### New Features
- Added remote MCP servers: define an `AiMcpServer` and each of its tools becomes available as `{slug}__{tool}` (e.g. `mdn__search`), with its real input schema, granted through `AiSystem::allowed_tools` like any local tool. Only tools you name are offered; a tool added to the server later is not granted until you add it, and a local tool with the same name wins.
- Added `Services/Management/AiMcpServerManager` (create/update/delete/list/sync, static `rules()`). Creating or updating a server syncs its tool catalog and returns the sync report; a failed sync is reported and recorded on the server, never thrown. A server's `slug` is immutable, and `delete()` returns the number of systems that had granted one of its tools.
- A server's tool catalog is stored at sync time, so turns, operator runs and "available tools" listings never contact the server to discover tools — a down server does not delay a turn.
- Remote server auth supports `none`, `bearer`, custom `headers`, and OAuth `client_credentials` (token cached until near expiry, refreshed once on rejection, endpoint discovered from OAuth metadata when `token_endpoint` is omitted). Credentials are stored encrypted and never returned by `list()`. The conversation's user and visitor identity is never sent to a remote server.
- A failing remote server no longer ends the turn: an unreachable server, timeout, malformed response, or error result becomes a tool error the model can read, and after a connection failure or timeout the rest of that turn's calls to the same server fail immediately. A tool whose schema cannot be converted is stored but not offered, and `list()` shows it as unavailable with the reason.
- Added `ai:sync-mcp-servers [slug]`, scheduled daily at 03:30, which refreshes stored catalogs and exits non-zero if any server fails to sync.
- Added the `RemoteMcpToolDefinitionChanged` event, dispatched when a re-sync finds a tool's description or schema changed. The new definition takes effect immediately.
- Added `remote_mcp.enabled` (master switch, default `true`), `remote_mcp.default_timeout_seconds` (default `10`) and `remote_mcp.max_timeout_seconds` (default `30`) config keys.

### Known Issues
- Only the HTTP transport is supported for remote servers; stdio servers and interactive (authorization-code) OAuth are not.
- No heartbeat is sent while a remote tool call is in progress. Keep `remote_mcp.max_timeout_seconds` low when serving turns over a synchronous stream; dispatched turns are unaffected.

## [0.15.0] — 2026-08-31

A turn cut short is no longer thrown away. Whatever it produced is persisted and
marked incomplete, and it is no longer logged as a clean success.

### Breaking Changes
- `ai_interaction_logs.status` has a new value, `aborted` (`AiInteractionStatus::Aborted`), used for a turn whose caller hung up mid-stream. It was previously recorded as `success`. Code matching exhaustively on the enum must handle the new case, and dashboards that count everything other than `success` as a failure will now count cancellations among them. The tokens an aborted turn burned still count towards conversation usage totals.
- The `message_delta` event's `delta.stop_reason` has a new value, `incomplete`, for a turn that never finished — the caller hung up, or the max-stream-duration guard cut the generation off. Such turns previously reported `end_turn`, which was indistinguishable from a clean finish. The same value is stored on `ai_llm_messages.response_data.stop_reason`.
- An interrupted turn now leaves an assistant message in the transcript even when it produced no text, so `ChatBotPresenter::transcript()` can return assistant rows whose `content` is empty. Each row carries a new `incomplete` boolean; render a flagged row as an interrupted reply rather than as a blank answer.
- Two new migrations (`ai_turn_runs`, `ai_turn_events`) ship with this release. Re-publish migrations and run them, even if you do not use the dispatched-turn API — `ai:prune-turn-events` is scheduled by default and expects the tables.

### New Features
- An interrupted assistant message records why in its `metadata`: `incomplete` plus an `incomplete_reason` of `client_aborted` or `max_stream_duration`.
- Turns now emit a heartbeat while the provider is silent (`conversations.heartbeat_seconds`, default 5, `0` to disable). It is encoded as an SSE comment, so existing clients ignore it; it keeps intermediaries from timing out mid-answer and lets an abandoned turn be noticed in seconds rather than minutes. Currently emitted for `openai-compatible` and `lm-studio` systems; a turn dispatched as a job heartbeats for every provider.
- Added `AiPersonaConversationService::dispatchTurn()`, `resumeTurn()` and `cancelTurn()`: a turn can run as a queued job that records its events, so a browser reload resumes it instead of killing it. Events are framed with an SSE `id:` carrying their sequence, and the published client reports it through a new `onSequence` callback. Requires the two new migrations and a queue worker.
- Added `ai:prune-turn-events` (scheduled daily at 03:15) to clear finished turn runs past `turns.retention_days`.

### Bug Fixes
- A turn interrupted before the model emitted any text is no longer discarded entirely. It previously left the user's message in the transcript with no reply beneath it and no record that a turn had ever run — the failure mode was most visible with a large context, where a model can spend minutes processing the prompt before its first token.
- Tool calls an interrupted turn already made are now persisted with it. They were dropped along with the rest of the turn, leaving the next turn's history with no record that a tool had run and changed state.
- The maximum-stream-duration guard now applies during provider silence. It was only evaluated when an event arrived, so a stalled stream could run well past `conversations.max_stream_seconds` before anything noticed.

## [0.14.1] — 2026-08-30

### New Features
- LM Studio model listings now include `size_bytes`, the on-disk size of each model, alongside the existing id, display name, load state, context length, and capabilities.

## [0.14.0] — 2026-08-27

Renames the reactive, human-triggered persona concept from "chat bot" to
"persona", and adds a new "operator" concept for AI work a host dispatches
itself rather than a human triggering it.

### Breaking Changes
- **`AiChatBot` is renamed to `AiPersona`** — model, `ai_chat_bots` table (→ `ai_personas`), `AiChatBotManager` (→ `AiPersonaManager`), `AiChatBotConversationService` (→ `AiPersonaConversationService`, same constructor), the `ai_chat_bot_id` foreign key on `ai_conversations`/`ai_interaction_logs` (→ `ai_persona_id`), and `AiChatBot::featureKey()`'s `chat-bot:*` prefix (→ `persona:*`). Migrations rename the table/columns directly; re-publish and run migrations.
- **`AiChatBot::prompt_template`'s placeholders are renamed** — `{{bot_name}}`/`{{bot_slug}}`/`{{bot_description}}` → `{{persona_name}}`/`{{persona_slug}}`/`{{persona_description}}`. Update any stored prompt templates.
- **Existing `ai_system_feature_defaults.feature` rows using the `chat-bot:` prefix are migrated to `persona:`.** A host's own `feature_keys` config listing `chat-bot:*` keys is not something a migration can touch — update it manually.
- Host apps providing a factory for the renamed model must rename `Database\Factories\AiChatBotFactory` to `AiPersonaFactory` (per `Factory::guessFactoryNamesUsing`).

### New Features
- Added `AiOperator`: a persona-shaped config for bounded, single-shot AI work triggered by something other than a human chat message — a host observer, an event listener, a scheduled job. The package owns no trigger or scheduling system; dispatching one is `dispatch(new RunAiOperatorJob($operator, $context))`, the same shape `ProcessAiMemoryJob` already uses.
- `AiOperator::prompt_template` supports `{{dotted.path}}` placeholders resolved against the `$context` array passed at dispatch time. An unresolved placeholder fails the run before any provider call is made.
- An operator run is bounded — one prompt through the same agentic tool loop a chat turn uses, then done — and is recorded as an `AiConversation` (`feature` = `operator:{slug}`), so it gets request/response logging, raw exchange capture, and cost tracking with no operator-specific logging path.
- Added `Services/Management/AiOperatorManager`, mirroring the package's other management services (create/update/delete/list, static `rules()`).
- Added `AiSystem.duplicated_at`, set to the current time whenever `AiSystemManager::duplicate()` creates a copy, so a duplicated system is distinguishable from an originally-created one.

## [0.13.0] — 2026-08-27

### New Features
- Added the `page_reload` turn event: a tool can add `_page_reload: true` to its structured result to tell the browser server state changed and the page should reload. The published client gains a matching `onPageReload` callback.
- The published client gains an `onToolProgress` callback for the existing `tool_use_progress` event.

### Bug Fixes
- LM Studio's own engine-level failures (e.g. context-size-exceeded) are no longer silently dropped. These arrive as a flat error frame — `{"code","message","type"}` with no `"error"` wrapper and no `"choices"` — distinct from OpenAI's nested shape, and were previously treated as a contentless delta.
- `tool_use_progress` is now part of the published `ChatStreamEvent` type declarations and is dispatched by the published stream client — previously the type was undeclared and the client silently dropped the event.

## [0.12.1] — 2026-08-26

### Bug Fixes
- `AiSystem.enable_thinking = false` is now actually enforced. Previously the flag was stored but reasoning content still reached the chat UI regardless, and LM Studio was never told to disable reasoning on its end.

## [0.12.0] — 2026-08-26

### New Features
- `fetch-web-page` and `http-request`'s body/content length caps (`WebFetcher::maxBodyLength()` / `maxContentLength()`) are now configurable via `CODE_TALKER_MAX_BODY_LENGTH` / `CODE_TALKER_MAX_CONTENT_LENGTH`, replacing the previous hardcoded `MAX_BODY_LENGTH` / `MAX_CONTENT_LENGTH` constants.
- Added per-`AiSystem` web-tool scoping: `web_tool_policy` (`allowed_domains`, `credentials`) restricts `fetch-web-page`/`http-request` to specific domains for a given system, enforced by `HostGate` ahead of the model's own `request_policy`. A system with no `web_tool_policy` is unrestricted, matching pre-existing behavior.
- Added `request_policy.allow_credential_headers` to `http-request`, letting a model-supplied `Authorization`/`Cookie` header through — for a credential the model was handed directly in conversation, with no way for an operator to pre-configure it. Honored only when the `AiSystem`'s `web_tool_policy.allowed_domains` is non-empty; the model's own declaration is never sufficient by itself. A model-supplied credential wins over a configured one for the same header name, and is not carried to a different host on redirect even within the allow-list.
- Added `code-talker.tools.web_fetcher.allowed_domains` (`CODE_TALKER_MCP_ALLOWED_DOMAINS`), the operator boundary `allow_credential_headers` checks for a caller with no `AiSystem` at all — the external MCP server, which has no conversation and so no `web_tool_policy` to consult. Without it, an MCP caller could never satisfy `allow_credential_headers` no matter what was configured. Consulted only in that no-conversation case; a chat-bot conversation's own `AiSystem` remains the sole authority for its calls, including an intentional choice to stay unrestricted.

### Bug Fixes
- `AiSystem.pricing_profile` is `@deprecated` and slated for removal — redundant with `code-talker.providers.*.pricing`, which `ConversationUsageService` already falls back to.
- `ConversationTurnRunner` now yields a `tool_use_progress` frame (`{type, text, tools}`) alongside each `ToolCall` event, matching the frame `TargetedResumeService`'s own separate turn loop already emitted. Previously a standard chat-bot turn gave the browser no indication a tool was even running — `StreamTranslator` intentionally never forwards the raw `ToolCall`/`ToolResult` events (they carry provider-shaped payloads, not display text), so a turn that called a tool multiple times, especially one retrying after an error, streamed nothing but silence between reasoning/text deltas. No frontend change needed: the host's `ChatInterface`/`useChatStream` already render this frame for any chat surface, since `TargetedResumeService` was already emitting it.
- **`http-request`'s `headers` input is now a string, not an object** — `"Name: value"`, one per line, e.g. `"Authorization: Bearer abc\nX-Request-Id: 123"`. Observed live: a small/local model (LM Studio, Qwen) correctly wrote out the equivalent JSON when asked to just describe the call in prose, but its actual structured tool-calling output emitted an empty `{}` for the same field on the real call — `laravel/ai`'s `mapStreamToolCalls()` decodes exactly what the model's function-calling stream contains, so this was the model's constrained-decoding path failing on a nested object, not a parsing bug. A flat string is a dramatically easier target for that same decoding path. **Breaking** for any caller passing an object today; update to the line-based string format.

## [0.11.0] — 2026-08-25

This release turns Code Talker into a pure library. The package no longer registers routes, ships controllers, or renders pages — a chat turn is a method call, and management is a service layer the host drives from its own screens. Conversation history is now read through `laravel/ai`'s `ConversationStore`, which is what unblocks attachment replay. Two new chat-bot tools round it out: general HTTP requests, and the current date and time.

This is a large upgrade from 0.10.0. Read every entry under Breaking Changes first.

### Breaking Changes
- **All routes are removed.** The package registers no chat routes and no admin routes, and ships no route files. Hosts define their own routes and drive the services directly. The `code-talker-routes`, `code-talker-chatbot-routes`, and `code-talker-admin-routes` publish tags are gone.
- **The admin controllers and form requests are removed**, along with `/admin/ai/*`. Rewrite host controllers against `Services/Management/` — `AiSystemManager`, `AiSystemPromptManager`, `AiChatBotManager`, `AiConversationManager`, `AiMemoryManager` — reusing each manager's static `rules()` (or `createRules()` / `updateRules()`) so validation stays in one place. `admin_middleware` remains in config for hosts still loading a published copy of the removed route file.
- **`ChatBotController` and `SendAiChatBotMessageRequest` are removed**, along with `ConversationSessionStore`, `ChatBotAccessGuard`, `ChatBotRouteUrls`, `ConversationHistoryPresenter`, `ChatBotPagePayload`, `ChatBotIndexPayload`, and `ChatStreamResponse`. Start a turn with `AiChatBotConversationService::startConversation()` and drive it with `continueConversation()`.
- **`continueConversation()` now yields structured event arrays, not pre-encoded SSE strings.** `SseFrameEncoder` owns the framing, so a host can deliver a turn over something other than SSE. The wire format itself is unchanged when you pipe it through the encoder.
- **Resolving a conversation across requests is now the host's concern.** The session entry and the `ai_chat_bot_current` cookie are gone; use `findByChatHashOrUuid()`.
- **Cancellation must be supplied when a turn runs outside a request.** The default reads `connection_aborted()`, which reports 0 in a queue or console context — a turn driven from there never cancels unless the host passes a signal to `usingCancellationCheck()`.
- **`TranscriptBuilder` and `ConversationTranscript` are removed**, replaced by `Services/Conversation/CodeTalkerConversationStore`.
- **A new migration is required.** `2026_08_16_000001_add_message_structure_to_ai_conversation_messages_table` adds `user_id`, `agent`, `attachments`, `tool_calls`, `tool_results`, and `usage`. Re-publish migrations and run them.
- **The `inertia` config block is removed and `inertiajs/inertia-laravel` is no longer a dependency.** Hosts that render the chat pages keep their own components and install Inertia themselves.
- **`fetch-web-page` now fetches public hosts only by default.** A URL whose host resolves to a loopback, link-local, or private-network address is refused unless the call declares `request_policy.allow_private_hosts`. A bot that reads pages on an internal network needs that declaration added. The tool also gains a fifth input, `request_policy`, and reports the final destination as `url` when a fetch redirects.
- Only the Inertia page-prop half of the published TypeScript declarations is gone. The stream contract — `StreamTranslator`, the `ChatStreamEvent` union, and the `code-talker-types` and `code-talker-client` publish tags — is unchanged.

### New Features
- Added `Services/Management/`, five managers covering every operation the removed admin controllers performed, so the package ships management capability without shipping controllers or components.
- Added `Services/Conversation/CodeTalkerConversationStore`, implementing `laravel/ai`'s `ConversationStore` over `ai_conversations` / `ai_conversation_messages` and bound over the framework default. History replays as real message objects — a user turn keeps its attachments, a tool-using turn replays as a call plus its result — where before it was flattened to bare user and assistant text.
- `TurnRecorder` now persists tool calls, tool results, and usage, so recorded turns are replayable.
- Added the `http-request` tool: `GET`/`POST`/`PUT`/`PATCH`/`DELETE` with an optional body, decoding JSON, XML, plain text, and HTML. JSON and XML come back as structures rather than strings; binary content types are refused rather than base64-encoded into the transcript.
- Added the `get-temporal-information` tool, returning the current date and time in an optional IANA timezone or fixed UTC offset, with the calendar parts pre-computed.
- Added `code-talker.tools.http_request.credentials`, a host-keyed map of request headers. `http-request` strips model-supplied authentication headers and attaches credentials from this config instead, so a token is never visible to, or inventable by, the model.
- Both web tools accept an optional `request_policy` declaring which hosts a call may reach, enforced by one shared `Services/Web/HostGate`. `http-request` requires it; `fetch-web-page` defaults to public hosts only when it is omitted.
- Both web tools re-check every redirect destination against the same policy instead of following redirects automatically, and connect to the address that was checked rather than resolving the host a second time.
- Both new tools are registered on the external MCP server alongside `fetch-web-page`, `search-web`, and `scan-memories`.

### Bug Fixes
- `syncFeatureDefaults()` and the prompt-delete cascade now run in transactions. Both were multi-write with none, so a mid-way failure could leave a feature with no default system and `AgentFactory::forFeature()` throwing.
- `feature_defaults` validates against `config('code-talker.feature_keys')` rather than hardcoded host-app feature names.
- `duplicate()` no longer silently drops feature defaults; copying them is now an explicit argument.
- `storeConversation()` throws a clear error rather than failing on a NOT NULL constraint when the contract cannot supply the required `AiSystem`.
- Removed an unused `AiMemoryService` parameter, an unused import, and an `_id` key that read a camelCase property off a snake_case column.

### Known Issues
- A host that has already registered its own tool named `http-request` through `addToolDirectory()` will shadow the package's, because host directories are discovered after package ones. Check for the collision before upgrading.

## [0.10.0] — 2026-07-27

This release restructures the three largest classes in the package — `ChatBotController`, `AiChatBotConversationService`, and `SearchWebTool` — into focused collaborators without changing any behavior, and documents the previously undocumented contract between the package's HTTP surface and a host app's chat UI. Host apps that only consume the package's routes, services, and tools need to change nothing; the one exception is noted below.

### Breaking Changes
- `ChatBotController`'s constructor now takes ten dependencies instead of two, and its `protected` helper methods have been removed (`storedState`, `putStoredState`, `storedConversation`, `historyForBot`, `routeUrlFor`, `abortIfInaccessible`, `rememberConversation`, `clearStoredState`, `requestAccessPath`, `stateKey`, `forgetLegacyCookies`). **Any host app that subclasses this controller will fatal on instantiation**, whether or not it used those helpers, because a subclass calling `parent::__construct()` with the old two arguments no longer matches. Rather than chasing the new signature, override behavior by binding replacements for the extracted collaborators in `Jvjvjv\CodeTalker\Services\ChatBot\` — `ChatBotPagePayload`, `ChatBotIndexPayload`, `ChatBotStatusResolver`, `ConversationSessionStore`, `ChatBotAccessGuard`, `ChatBotRouteUrls`, and `ConversationHistoryPresenter` — which are resolved from the container and stay stable across future refactors.

### New Features
- The frontend contract is now documented under **Frontend Integration** in the README: every Inertia prop on both chat pages, every server-sent event with its payload and framing, the `[DONE]` terminator, the `X-Chat-Hash` header, and the readiness/warmup JSON shapes. Both contracts are declared public API covered by the package's version.
- Added `php artisan vendor:publish --tag=code-talker-types`, publishing TypeScript declarations for the Inertia props and the stream event union.
- Added `php artisan vendor:publish --tag=code-talker-client`, publishing a dependency-free TypeScript stream client that turns the SSE response into typed callbacks (`onText`, `onReasoning`, `onDone`, `onError`, …) with an abort handle. It imports nothing but browser APIs, so it works with any UI framework. No UI components are shipped.
- Added `code-talker.inertia.components`, letting a host point the chat pages at its own Inertia components without subclassing the controller. Defaults to the previous `ai/ChatBot` and `ai/ChatBotsIndex`.
- Chat-bot conversation logic is now available as separately usable services: `Services\ChatBot\` covers session state, access, route URLs, page payloads, and SSE streaming, and `Services\ChatBot\Conversation\` covers the turn runner, transcript, system prompt, and turn recording.
- `search-web` engines are now individually addressable through the `Services\Mcp\Tools\ChatBot\SearchWeb\SearchEngine` contract, with one implementation per engine behind `SearchEngineRegistry`.

### Bug Fixes
- Corrected the README's description of conversation state, which still documented the per-bot `ai_chat_bot_conversations_{id}` cookie scheme replaced in 0.7.1.

## [0.9.2] — 2026-07-21

This patch fixes the Fetch Web Page tool test so it matches the shared scraper user-agent helper instead of a stale hardcoded expectation.

### Bug Fixes
- Updated the Fetch Web Page tool feature test to assert the shared scraper user-agent string generated by `WebScraperUserAgent`, removing the old JayScraper-specific expectation.

## [0.9.1] — 2026-07-21

This patch improves web-fetch reliability on bot-sensitive sites by making package web requests look more like normal browser navigation while still identifying CodeTalker.

### New Features
- `WebScraperUserAgent` now builds a browser-style user-agent prefix and appends a package identifier token (`CodeTalker/<version>`) with contact/purpose metadata, with the package version resolved dynamically via Composer instead of being hardcoded.

### Bug Fixes
- `fetch-web-page` and HTML search requests now include additional browser-like headers (`Accept-Encoding`, `Cache-Control`, `Pragma`, and `Upgrade-Insecure-Requests`) alongside the existing `User-Agent`, `Accept`, and `Accept-Language` headers, reducing empty/partial responses from bot-filtered endpoints.

## [0.9.0] — 2026-07-21

Chat-turn errors now carry a stable reason code, the max-stream-duration guard budgets each provider request instead of the whole turn, and a timed-out turn no longer discards whatever it already produced. `AiSystem` configs can also pass raw sampling parameters straight through to the provider.

### New Features
- Added `AiSystem.config.provider_options`, a raw pass-through merged directly into every provider request body (e.g. lm-studio/openai-compatible `frequency_penalty`, `repeat_penalty`, `top_k`, `seed`, `stop`). `model`, `messages`, `stream`, `tools`, and `tool_choice` are stripped so a pasted config can't silently clobber the request; everything else, including `response_format`, passes through untouched.
- Chat-turn errors — the streamed SSE `error` event plus the persisted `AiInteractionLog` record — now carry a `reason` code (`max_stream_duration` vs `provider_error`) alongside the message, so consumers can distinguish a genuine max-duration abort from other provider failures without pattern-matching on error text.
- `fetch-web-page` gained `keep_html`, `target_selector`, and `truncate_content` inputs alongside the original `url`, letting callers keep raw HTML, scope extraction to a CSS selector, and opt out of the default 20,000-character truncation.

### Bug Fixes
- The max-stream-duration guard now resets on every new provider request — a "Continue." reprompt, or an internal tool-call step within the same turn — instead of budgeting the entire turn from a single start time. A multi-step tool-calling turn can no longer be cut off early just because earlier steps used up most of the turn's original allowance.
- A turn that hits the max-stream-duration guard no longer discards everything it streamed. Previously the abort skipped straight past the persistence code, so a model stuck deliberating with no final answer (but potentially substantial reasoning output) vanished without a trace and left no response record at all. The turn is still logged as a failure, but now keeps whatever text/reasoning content it produced.
- `fetch-web-page`'s readable-text extraction no longer leaks `<head>` content (notably the page `<title>`) into the extracted `content`, concatenated with no separator onto the start of the visible text.

## [0.8.0] — 2026-07-21

Adds an `ai:read-exchange` command for inspecting captured provider exchanges and fixes reasoning output being dropped for openai-compatible providers.

### New Features
- Added `php artisan ai:read-exchange {ai_llm_message_id?}`, which prints the captured raw request/response for a provider exchange; omitting the id opens an interactive chatbot → conversation → message drilldown.

### Bug Fixes
- Openai-compatible providers (including LM Studio) no longer silently drop `reasoning_content`/`reasoning` deltas during streaming — laravel/ai v0.9.0's built-in gateway omitted them, so reasoning models never streamed their "thinking" to the chat UI. `ReasoningOpenAiCompatibleGateway`/`Provider` now re-emit them.

## [0.7.1] — 2026-07-20

Fixes unbounded chat-cookie growth that could push the request `Cookie` header past the web server's limit and produce a `400 Request Header Or Cookie Too Large`.

### Bug Fixes
- The chatbot no longer writes a separate `ai_chat_bot_conversations_{botId}` cookie per bot with an ever-growing conversation `history` array. Visitors who used several bots accumulated many large encrypted cookies (all at path `/`, sent on every request), eventually exceeding the server header limit. Conversation state is now kept in a single small `ai_chat_bot_current` cookie holding only the visitor's most recent conversation id.
- Legacy `ai_chat_bot_conversations_*` cookies are now actively forgotten whenever a chat route is visited, so existing bloated browsers recover on their own (once the request can reach the app).

### Behavior Changes
- Per-bot conversation history now lives only in the server-side session, so the conversation switcher persists within a session but is not restored across sessions for anonymous visitors; the single cookie still resumes their latest chat. History is also capped defensively (25 entries) in the session.

### Upgrade Note
- Because the old cookies bloat the request header, the web server rejects the request *before* the app runs — so the app cannot clear them until the request gets through. Ensure the origin header buffer is large enough (e.g. nginx `large_client_header_buffers 8 32k;`) so already-affected browsers can reach the app and have their legacy cookies forgotten.

## [0.7.0] — 2026-07-20

Memory extraction now runs once per conversation instead of once per assistant message, triggered by a new idle-completion command, and the admin memory rebuild no longer destroys the memories it cannot rebuild.

### Breaking Changes
- Memory extraction no longer runs after every assistant message. It fires once, when the new `ai:complete-idle-conversations` command marks a conversation `Completed` after `conversations.idle_timeout_minutes` (default 30) without a new message — so memories now appear up to roughly 45 minutes after a chat ends rather than immediately.
- Hosts that disable the package scheduler (`'schedule' => false`) must register `ai:complete-idle-conversations` themselves, or memory extraction will never run at all.
- Added the `conversations.idle_timeout_minutes` config key; re-publish or reconcile `config/code-talker.php`, since the package merges config shallowly.

### New Features
- Added `ai:complete-idle-conversations` (scheduled every 15 minutes), which marks inactive `Active` conversations `Completed` and thereby drives memory extraction. Supports `--minutes` to override the idle window and `--dry-run` to preview.
- Captured memory-extraction exchanges now record their `ai_conversation_id`, so memory calls can be correlated with the conversation they analyze when auditing token spend.

### Bug Fixes
- `AiMemoryService::rebuildMemories()` no longer deactivates every memory for a feature when no completed conversations exist to rebuild from; it leaves existing memories untouched and logs a warning instead. Because nothing previously set a conversation to `Completed`, the admin "Rebuild" action deleted all memories for the feature, rebuilt nothing, and reported success.
- `AiConversationObserver` now runs. No code path previously wrote `AiConversationStatus::Completed`, leaving the observer and its memory-extraction dispatch unreachable.
- Memory analysis skips conversations with no non-system messages rather than sending an empty transcript to the provider.

### Known Issues
- Reasoning tokens from `openai-compatible` providers (including LM Studio) may not surface as reasoning events or be included in reported usage, so logged token totals can under-report what the model actually generated.

## [0.6.0] — 2026-07-19

This release re-platforms the entire provider layer onto Laravel's first-party [laravel/ai](https://github.com/laravel/ai) SDK, deleting the five hand-rolled provider services and three vendor SDK dependencies while keeping the browser SSE wire format, database schema, routes, tool contracts, memory system, and admin endpoints unchanged.

### Breaking Changes
- Requires PHP `^8.3` (was `^8.2`) and Laravel `^12.62 || ^13.15`, driven by the new `laravel/ai` dependency.
- Removed `AiClientContract`, `CanLoadModels`, `AiClientFactory`, the `ExecutesAiTools` concern, and the five provider services (`ClaudeService`, `OpenAiService`, `GeminiService`, `GrokService`, `LmStudioService`); host code using them should migrate to `AgentFactory::forSystem()` / `forFeature()` (returns a laravel/ai agent) or use laravel/ai directly.
- Removed the `anthropic-ai/sdk`, `openai-php/client`, and `google-gemini-php/client` dependencies in favor of `laravel/ai`.
- Removed the unused `providers.*.api_key`, `providers.*.model`, and `providers.*.max_tokens` config fallbacks from `code-talker.php`; credentials and model settings come exclusively from `AiSystem` records (the `pricing`, `base_url`, `api_version`, and `server_url` keys remain).
- `AiLlmMessage` logging granularity changed: tool-use iterations are no longer separate request/response rows — each agent invocation logs one request and one response row whose `response_data.events` list now includes tool calls and tool results; `N.M` sub-turn numbering is only used for max-token continuations.

### New Features
- Chat streaming, the agentic tool loop, and provider normalization now run on the official laravel/ai SDK; `AiSystem` records are bridged to laravel/ai providers at runtime, so hosts do not need to publish or configure `config/ai.php`.
- Enabling `enable_thinking` on an Anthropic `AiSystem` now requests extended thinking (streamed to the browser as reasoning deltas).
- The `grok` provider now targets laravel/ai's `xai` driver, and `lm-studio` / `openai-compatible` systems run on the `openai-compatible` driver with per-system base URLs.

### Known Issues
- Whether LM Studio `reasoning_content` deltas surface as reasoning events depends on laravel/ai's `openai-compatible` driver; if unsupported, reasoning display for LM Studio degrades silently while text streaming is unaffected.

## [0.5.0] — 2026-06-19

This release re-platforms chatbot tools onto [laravel/mcp](https://github.com/laravel/mcp) so a single tool class runs in the local chat loop and can also be exposed to external MCP clients (Claude Desktop, Grok, etc.).

### Breaking Changes
- Tools are now [laravel/mcp](https://github.com/laravel/mcp) `Laravel\Mcp\Server\Tool` classes (`#[Description]`/`#[Name]` attributes, `schema(JsonSchema)`, `handle(Request): Response`); the `AiToolHandlerContract` interface is deprecated but still discovered and dispatched for one release to ease migration.
- Tools should depend on the new `Jvjvjv\CodeTalker\Support\ToolContext` for the current user/conversation instead of injecting `AiConversation` directly.
- The built-in tools were renamed from snake_case to kebab-case (`fetch_web_page` → `fetch-web-page`, `search_web` → `search-web`, `scan_memories` → `scan-memories`); run the published migration to remap persisted `AiSystem::allowed_tools` values.
- Added `laravel/mcp` as a dependency.

### New Features
- The built-in chatbot tools can now be exposed to external MCP clients through the bundled `CodeTalkerServer`, configurable (and disabled by default) under the new `code-talker.mcp` config key with web (HTTP, authenticated) and local (stdio) transports.
- The `scan-memories` tool is advertised by the external MCP server only to callers with a user identity (via `shouldRegister()`), so anonymous callers never see it.

## [0.4.1] — 2026-06-18

This patch release fixes PHP 8.2 and 8.3 compatibility for the `symfony/dom-crawler` dependency added in 0.4.0.

### Bug Fixes
- Widened the `symfony/dom-crawler` constraint to `^7.4 || ^8.1` so the package installs cleanly on PHP 8.2 and 8.3 (resolves v7.4.x) while still using the latest v8.x on PHP 8.4+.

## [0.4.0] — 2026-06-18

This release adds a multi-engine web search tool for chatbots, centralizes the package-owned scraper user agent, and fixes web-search execution paths.

### New Features
- Added `SearchWebTool` (`search_web`) to query Bing, Google, DuckDuckGo, and Brave, returning normalized results plus markdown links/snippets and pagination inputs for continued searching.
- Added `WebScraperUserAgent` support helper to centralize the package-owned JayScraper user agent string and reuse it across chatbot web tools.
- Added explicit API-versus-web execution paths for search providers (`*ViaApi` / `*ViaWeb`) to keep provider-specific transport logic separated and easier to maintain.

### Bug Fixes
- Updated chatbot web-fetch/search requests to use a single shared scraper user agent source instead of duplicating user-agent construction logic in multiple tools.
- Fixed the DuckDuckGo web search branch to use the web HTTP client path consistently after the API/web transport split.

## [0.3.0] — 2026-06-14

This release adds a web page fetching tool for chatbots and fixes the LM Studio provider payload.

### New Features
- Added `FetchWebPageTool` that fetches a URL and returns its readable text content, stripping scripts, styles, and markup and truncating to 20 000 characters.
- Exposed `ChatBotController` helper methods as `protected` so host applications can subclass the controller and override session, state, and routing behaviour.

### Bug Fixes
- Removed `enable_thinking` from the LM Studio chat payload; it is not a valid OpenAI-compatible field and was rejected by the endpoint.
- Added `ttl=600` to the LM Studio payload to keep the loaded model alive for 10 minutes between requests.

## [0.2.4] — 2026-06-10

This patch release removes dead admin controller payload data and fixes the package's admin tools controller inheritance so the class loads cleanly.

### Bug Fixes
- Removed the unused `navBlocks` payload and helper from the admin tools controller so the package no longer sends dead data to the Inertia page.
- Replaced the nonexistent `BaseAdminController` inheritance path in the admin tools controller so the package autoloads the controller cleanly.

## 0.2.3 — 2026-06-07

This release improves package route registration and adds a supported way for host applications to publish and customize the package route files.

### New Features
- Added publishable route stubs for the package's public chatbot and admin routes via `php artisan vendor:publish --tag=code-talker-routes`.
- Renamed the published route stub filenames to `routes/codetalker-chatbots.php` and `routes/codetalker-admin.php` so host-application overrides are clearer and less generic.
- Updated the package to prefer those published host-app route files automatically when they exist.

### Bug Fixes
- Deferred package route registration until the host application's service providers have finished booting so literal routes such as `/login` are registered before the package root-level chatbot wildcard routes.

## 0.2.2 - 2026-06-07

This patch release removes a stray package dependency and limits the default system prompt seed migration to only include the three generic prompts owned by the package. 

Host applications that previously relied on the default system prompt seed to create `TargetedResumeService`-specific prompts will need to create those prompts manually or through a custom migration after upgrading.

### Bug Fixes

- Removed the package's stray `TargetedResumeService` dependency from the default system prompt seed migration.
- Limited the default system prompt seed migration to the three package-owned generic prompts and moved their IDs into a dedicated package support class.

## 0.2.1 — 2026-06-07

This patch release fixes package migrations so host applications are no longer required to use UUID user keys.

### Bug Fixes
- Replaced hardcoded UUID user foreign keys in the package migrations with model-aware user key definitions based on `code-talker.user_model`.
- Removed follow-up migration steps that attempted to retype `user_id` columns as UUIDs after the initial tables were created.
- Changed memory user scoping storage to use a generic string identifier so integer, UUID, ULID, and custom string user keys are all supported.

## 0.2.0 — 2026-06-07

This breaking release removes package-managed chatbot authentication and authorization so the package only manages AI chatbot behavior and the consuming application owns all access rules.

### Breaking Changes
- Removed package-level chatbot visibility and role concepts. `AiChatBot::is_public`, any previous `allowed_roles` usage, and the package's private-bot authorization hook are no longer supported.
- The package no longer decides who may access `/chats`, `/chat/{slug}`, `/{slug}`, or `/admin/ai/*`. Host applications must enforce all chatbot and admin access through their own middleware, gates, and policies.
- Fresh installs no longer create the `ai_chat_bots.is_public` column, and upgraded installs must run the new migration that drops it.

### New Features
- Simplified the package boundary so chatbot management stays in the package while authentication and authorization live entirely in the consuming application.

### Bug Fixes
- Removed internal access filtering that mixed package chatbot behavior with host-application authorization decisions.

### Known Issues
- If you previously relied on package-managed bot visibility, move that logic into your application's `code-talker.middleware` and `code-talker.admin_middleware` configuration before upgrading. Public bot access should now be expressed by leaving chatbot routes open, and restricted bot access should be expressed with your application's own auth middleware, gates, or policies.

## 0.1.2 — 2026-06-06

This patch release updates the package's Inertia Laravel dependency line to keep host-application installs aligned with the current compatibility targets.

### Bug Fixes
- Updated the `inertiajs/inertia-laravel` dependency constraints and lockfile so host applications resolve the intended package version consistently.

## 0.1.1 — 2026-06-06

This patch release improves package compatibility and installation behavior for host Laravel applications, including verified Laravel 13 support.

### Bug Fixes
- Expanded the package constraints to support Laravel 13 and the matching Orchestra Testbench release line for package testing.
- Declared `inertiajs/inertia-laravel` as a runtime dependency so package controllers rendering Inertia responses install cleanly in host applications.
- Replaced the package-root test script with a direct PHPUnit invocation and added smoke coverage for package bootstrapping, route registration, and service bindings.
- Added a GitHub Actions workflow that validates the package and runs the test suite on PHP 8.3 and PHP 8.4.
- Documented the admin authorization expectations and suggested `bspdx/keystone` as a host-app option for the surrounding access-control layer.

### Known Issues
- This package is currently locked into using Inertia. This will be removed in a future version and will be a breaking change, so plan host-app integrations accordingly.

## 0.1.0 — 2026-06-04

This is the initial release, pulled from from the original application code for [Jason Vertucio](https://jasonvertucio.com). The package provides a comprehensive AI chatbot framework with support for multiple LLM providers, agentic tool use, memory management, and conversation tracking. It is designed to be highly extensible and customizable for a wide range of applications.

### New Features
- Multi-provider AI client abstraction supporting Anthropic (Claude), OpenAI, Google Gemini, xAI Grok, and LM Studio through a unified `AiClientContract` interface.
- `AiSystem` database-driven configuration model for storing provider credentials, model selection, temperature, context length, and capability flags per endpoint.
- `AiChatBot` model for defining named chatbot personas with customizable prompt templates, slugs, role-based access control, and optional visitor identity collection.
- Streaming chat response delivery via Server-Sent Events with an agentic tool-use loop (up to 6 iterations) that handles `max_tokens` continuation automatically.
- MCP-style tool registration: implement `AiToolHandlerContract` and register host-app tool directories via `CodeTalkerServiceProvider::addToolDirectory()`.
- Per-user and per-visitor memory system that extracts reusable insights from completed conversations and injects them into future system prompts via `AiFeatureMemory`.
- `AiSystemPrompt` model for managing reusable system prompt templates that can be assigned to `AiSystem` records.
- `AiSystemFeatureDefault` for mapping feature keys to default `AiSystem` records, enabling `AiClientFactory::forFeature()` lookups.
- Conversation usage tracking: token counts, per-model pricing snapshots, and aggregated cost stored on `AiConversation`.
- Full `AiLlmMessage` request/response logging for every LLM turn, including tool-use iterations.
- Admin route group at `/admin/ai/*` covering CRUD for systems, system prompts, chat bots, conversations, and memories (requires `can:manage-ai-tools`).
- Public chat routes auto-registered at `/chat/{slug}` or `/{slug}` (root access path), including shareable hash-based conversation URLs at `/chat/{slug}/{hash}`.
- Browser-side conversation state persisted via session and a 180-day cookie, supporting multi-conversation history per bot per browser.
- Artisan commands: `ai:backfill-system-capabilities`, `ai:backfill-conversation-usage`, `ai:sync-conversation-usage`.
- Scheduled jobs for conversation usage sync (twice daily) and backfill (daily at 02:30), disableable via `'schedule' => false` in config.
- Model factory resolution delegates to `Database\Factories\` in the host application, keeping the package itself factory-free.

# AGENTS.md

Guidance for coding agents (Claude Code, Codex, and others) working in this repository.

## What This Package Is

`jvjvjv/code-talker` is a Laravel package (not a standalone app) providing multi-provider AI chatbot infrastructure. It ships as a Composer library installed into a host Laravel application. Namespace: `Jvjvjv\CodeTalker`.

## Commands

```bash
# Install dependencies
composer install

# Run tests
composer test
# or directly
vendor/bin/phpunit

# Run a single test file
vendor/bin/phpunit tests/path/to/SomeTest.php

# Run a single test method
vendor/bin/phpunit --filter testMethodName

# Publish config to host app
php artisan vendor:publish --tag=code-talker-config

# Publish migrations to host app
php artisan vendor:publish --tag=code-talker-migrations

# Artisan commands provided by the package
php artisan ai:backfill-system-capabilities
php artisan ai:backfill-conversation-usage
php artisan ai:sync-conversation-usage
php artisan ai:prune-provider-exchanges
php artisan ai:complete-idle-conversations
```

## Architecture

### Core Flow

1. **`AiSystem`** (DB model) — represents a configured provider endpoint (model, API key, temperature, etc.). The central configuration object everything else references. Its `role` column (`Enums\AiSystemRole`: `chat` default, `audio`, `transcription`) says what it is for; a system serves exactly one role. `api_key`, `credentials`, and `web_tool_policy` are `$hidden` — read them by attribute access, never from `toArray()`.
2. **`Services/LaravelAi/`** — the bridge to Laravel's first-party `laravel/ai` SDK, which owns all provider communication, streaming normalization, and the agentic tool loop:
   - `AiSystemProviderConfigurator` injects a dynamic `ai.providers.code-talker-system-{id}` config entry per `AiSystem` at call time (driver mapping via `AiProvider::toLaravelAiDriver()`: `grok`→`xai`, `lm-studio`→`openai-compatible`, rest 1:1).
   - `AgentFactory::forSystem()` / `forFeature()` builds a configured `CodeTalkerAgent` — the single generic laravel/ai agent class whose per-system options (provider, model, maxTokens, temperature, maxSteps, timeout, providerOptions) are plain instance methods.
   - `BridgedTool` adapts `ChatBotToolRegistry` handlers to laravel/ai's `Tool` contract (via `ChatBotToolRegistry::toLaravelAiTools()`).
   - `StreamTranslator` converts laravel/ai stream events into the Anthropic-shaped SSE arrays the host-app browser chat UI consumes (`content_block_delta`, `reasoning_block_delta`, `message_delta`, `message_stop`). **This wire format is public API**, documented under "Frontend Integration" in `README.md` and typed in `resources/js/types/code-talker.d.ts`. Changing it is a breaking release; update the README, the declarations, and the tests that pin it together or not at all.
3. **`AiChatBot`** (DB model) — a user-facing chatbot persona referencing an `AiSystem`, with its own `prompt_template`, slug, access path, and tool settings.
4. **`AiChatBotConversationService`** — orchestrates the chat turn: builds the system prompt (base + memory), streams the agent response to the browser through `StreamTranslator`, re-prompts on max-token stops (up to 3 continuations; tool-use iterations run inside laravel/ai's loop, capped at 6 steps by `CodeTalkerAgent::maxSteps()`), logs `AiLlmMessage` request/response records per agent invocation, saves `AiConversationMessage` records, fires `ProcessAiMemoryJob`, and writes `AiInteractionLog`. The work itself lives in `Services/ChatBot/Conversation/` (see below); the service wires those together and owns the generator the browser consumes.

### Chat Bot Services

The package registers **no routes and no controllers**. A turn is a library call; the host owns the endpoint.

- **`Services/ChatBot/`** — `ChatBotPresenter` (visible transcript, per-bot lifetime cost, a user's conversation list), `ChatBotStatusResolver`, `SseFrameEncoder`.
- **`Services/ChatBot/Conversation/`** — `ConversationTurnRunner` (the continuation loop, a generator that yields SSE frames and returns a `TurnOutcome`), `TurnRecorder`, `ConversationHistory`, `SystemPromptBuilder`, `RequestPayloadBuilder`, `TurnSequence`, `ConversationTitle`, `ResponseBlocks`, `TurnGuards`, `TurnRequestPayload`.

Two constraints hold this together and are easy to break:

- `AiChatBotConversationService`'s constructor takes its **five dependencies positionally** (`AgentFactory`, `AiMemoryService`, `ConversationUsageService`, `RawExchangeContext`, `AiSystemProviderConfigurator`) — `AiChatBotConversationServiceTest` builds anonymous subclasses with exactly that signature. New collaborators are constructed from those five inside the constructor, never added as parameters.
- `streamElapsedSeconds()` and `clientAborted()` stay `protected` on the service and reach `ConversationTurnRunner` as closures bound to `$this` (via `TurnGuards`). Passing the values, or letting the runner call the guards itself, silently breaks every test that drives those guards by overriding them.

`continueConversation()` yields **structured event arrays**, never wire-encoded strings. `SseFrameEncoder` owns the framing, including the rule that a finished turn ends with `[DONE]` and an `error` event is terminal without it. Keeping the encoding out of the turn is what lets a host deliver it over something other than SSE — putting a `data: ` prefix back into the service would undo the change.

Two rules that used to live in the controller are enforced in `startConversation()` so a host cannot lose them: an inactive bot is refused, and a bot with `require_visitor_identity` is refused without a name and email. `continueConversation()` calls `generateChatHash()` for the same reason — it migrates a stale hash, so it is not merely a header value.

A terminal `error` event's `message` is never exception text: every producer yields `TurnErrorMessage::event($reason)`. The exception is logged and kept on `AiInteractionLog`; `continueConversation()` *returns* it (generator return value) so `RunConversationTurnJob` can read it with `getReturn()` for `AiTurnRun.error_message`.

Never pass a throwable to the logger or store its raw message. Log through `Support\RedactedException`: `context()` for an outbound call (class, a redacted message for transport failures, the status for a response error), `diagnostic()` for a failure an operator has to debug (message, location, and trace, with URL credentials masked). Stored error text (`AiInteractionLog`, `AiTurnRun.error_message`, `last_sync_error`) goes through `WebFetcher::redactText()`.

`continueConversation()` checks the persona (`persona_unavailable`) and system (`system_unavailable`) and re-activates a `Completed` conversation right after `generateChatHash()` — before history is read, the user message is written, or the try block starts, because `recordFailure()` needs a system. `startConversation()` runs visitor fields through `Support\VisitorIdentity::sanitise()` (throws `InvalidArgumentException` naming the field), and `SystemPromptBuilder::build()` takes the identity from its caller, never from `auth()`.

Cancellation is injectable via `usingCancellationCheck()`. The default reads `connection_aborted()`, which reports 0 outside a live request — so a turn driven from a queue or the console silently never cancels unless the host supplies a signal.

### Management Services

`Services/Management/` holds everything an admin UI needs, so the package can ship management capability without shipping controllers or components. Managers: `AiSystemManager`, `AiSystemPromptManager`, `AiPersonaManager`, `AiOperatorManager`, `AiConversationManager`, `AiMemoryManager`, `AiMcpServerManager`.

Each validates its own input via a static `rules()` (or `createRules()` / `updateRules()`) that a host can also feed to its own form request, keeping domain constraints in one place. Several invariants live here and nowhere else:

- **`provider` and `model` are immutable after creation.** `AiSystemManager::update()` re-attaches the stored values purely so `AiSystemCapabilityService` can hydrate capabilities against the right endpoint, then discards them before the write. Removing that dance silently allows a provider change that invalidates the stored capability flags.
- **A feature has exactly one default system.** `syncFeatureDefaults()` deletes the system's own claims, then any other system's claim on the incoming features, then recreates — all inside a transaction. Without the transaction a failure leaves a feature with no default and `AgentFactory::forFeature()` starts throwing.
- **Deletes cascade by hand.** Deleting a system deactivates (never deletes) its personas and operators and deletes its feature-default claims; deleting a prompt nulls `system_prompt_id` on referencing systems. Both report a count (personas, for a system) so the caller can build an accurate message, and both are transactional.
- **`api_key` is keep-on-blank on update**, like `credentials`. `update()` re-attaches the stored key only for capability hydration — without it `normalizeForPersistence()` blanks a local system's key on every edit — then drops it when none was submitted. LM Studio hydration sends the key and returns null on any HTTP failure, so the save keeps the stored capabilities.
- **A persona's or operator's `ai_system_id` must be an active, non-deleted, chat-role system.** `config` and `pricing_profile` must decode to objects, as must `config.provider_options` when present; `base_url` is required for `openai-compatible` on create.
- **`config`, `credentials`, and `pricing_profile`** arrive as JSON strings or arrays and are decoded before the write, because the model casts them to arrays.
- **`AiSystem` hides `api_key`, `credentials`, and `web_tool_policy`.** Package code must read them as attributes; `replicate()` (used by `duplicate()`) copies them regardless. `update()` keeps a stored `credentials`/`web_tool_policy` when it is omitted, null, `''`, or a JSON `null` — that check runs *after* `decodeJsonFields()` on purpose, because a JSON `null` is a string until decoded. Only an empty object clears, and a cleared `web_tool_policy` means *unrestricted*. Both must decode to an object, and `web_tool_policy` rejects keys other than `allowed_domains` and `credentials`, so a misspelt key cannot read back as no allow-list.
- **`ai_systems.credentials` is `text`, not `json`.** The cast is `encrypted:array`, and ciphertext is not a JSON document. SQLite reports a `json` column as text, so the test pins the MySQL SQL in pretend mode instead of the column type.

### Conversation Store

`Services/Conversation/CodeTalkerConversationStore` implements laravel/ai's `ConversationStore` over `ai_conversations` / `ai_conversation_messages`, bound over the framework default in `CodeTalkerServiceProvider::register()`. It replaced `TranscriptBuilder`, which could only rebuild history as bare text pairs — no tool calls, no attachments.

Three things about it are easy to break:

- **The package reads through the store but writes through `TurnRecorder`.** laravel/ai's `RememberConversation` middleware persists from a `then()` callback that fires only after the stream generator is fully consumed, and `ConversationTurnRunner` deliberately `break`s out of that loop on client abort and on the max-duration guard. Letting the middleware own writes would silently discard the partial output the 0.9.0 fix exists to keep. `CodeTalkerAgent::withStoredConversation()` sets the conversation id *without* a participant precisely so the middleware never attaches; `continue()` remains available to hosts who want upstream's behavior.
- **`system` rows are excluded from replayed history.** They hold the generated system prompt, which reaches the agent as instructions. Replaying one would duplicate it into the transcript. `ConversationHistory::systemPromptFor()` reads it separately.
- **Replay drops tool calls that have no recorded result** (and results that answer no kept call). An abort between two tool results would otherwise replay an unanswered call, which providers reject on every later turn. `TurnRecorder` still persists what happened; only the read side filters.
- **History must be read before the incoming user message is persisted.** That message becomes the prompt; replaying it as history too sends it twice. `AiChatBotConversationService::continueConversation()` reads first, then writes.

`CodeTalkerAgent` uses the `RemembersConversations` trait with `messages` aliased to `storedMessages`, then overrides `messages()` to return stored history followed by in-turn appended messages. A class method silently wins over a trait method, so dropping the alias would stop history being read at all.

### Frontend Contract

The package ships no UI and no endpoint. What it ships is the turn event vocabulary, in three places that must stay in sync:

- `README.md` → "Turn events" — the event table. The source of truth.
- `resources/js/types/code-talker.d.ts` — declarations mirroring the above, published via `--tag=code-talker-types`.
- `resources/js/code-talker-stream.ts` — a dependency-free stream client, published via `--tag=code-talker-client` (which also publishes the declarations, because the client imports them by relative path). It must import nothing but browser APIs and relative paths; `FrontendAssetPublishingTest` enforces that.

Two behaviors of the stream are counterintuitive and are pinned by tests — check them before touching the terminal paths:

- **`[DONE]` follows only a turn that finished.** Any `error` frame is terminal on its own. Enforced by `SseFrameEncoder`; pinned by `test_only_a_finished_turn_is_terminated_with_done` and `ChatTurnLibraryTest`.
- Nested config reads take **inline defaults** (`config('code-talker.inertia.components.chat_bot', 'ai/ChatBot')`). Laravel skips `mergeConfigFrom` entirely when the host has cached config, so a host that published `code-talker.php` before a key existed resolves `null` in production only.

Run `npm run typecheck` after touching anything under `resources/js`. Node is dev-only; host apps never install it.

### Provider Support

Providers (`anthropic`, `openai`, `openai-compatible`, `gemini`, `grok`, `lm-studio`, `elevenlabs`) are served by laravel/ai drivers — there are no per-provider service classes. Two gaps laravel/ai doesn't cover are handled by small raw-HTTP clients:
- `ProviderModelsClient` — lists available models per provider (surfaced by `AiSystemManager::availableModels()`, plus readiness checks).
- `LmStudioServerClient` — LM Studio's native `/api/v1/models[/load]` API (model listing, load status, explicit warm-up) plus server-URL normalization.

### Thinking Levels

`AiSystem::thinking_level` (`Enums\ThinkingLevel`: `off` < `minimal` < `low` < `medium` < `high` < `xhigh` < `max`, or null = send nothing) replaced the `enable_thinking` boolean. `Services/LaravelAi/Thinking/` holds one `ThinkingMapper` per provider, each with a static model-id table; `ThinkingResolver` clamps the level (next lower supported, else next higher) and decides visibility. `AgentFactory::providerOptionsFor()` merges the result *before* the `config.provider_options` passthrough, so a host can still override any key.

- **Visibility is enforced in `StreamTranslator`**, not by what is requested. `AiPersonaConversationService` builds the translator *after* the agent, from `$agent->showThinking()`, and the translator drops `ReasoningDelta` when it is false. Providers return reasoning unasked (local models, or an `off` that had to be sent as the lowest effort), so not requesting it is not enough. `ReasoningOpenAiCompatibleGateway` keeps its own gate too.
- **`off` is not always "no reasoning".** On a profile with `canDisable = false` the resolver sends the lowest level and hides the output. Anthropic has three `off` behaviors (omit / explicit `disabled` / impossible) by family — check `AnthropicThinking::FAMILIES` before adding a Claude model.
- **Unknown model ids resolve to the provider's newest family** (xAI: no effort field). `ModelId::normalize()` strips gateway prefixes and stacked date/`-vN`/`:…` suffixes; `ThinkingMappingTest` pins one id per family.
- **Both columns are written.** `AiSystemManager::resolveThinkingLevel()` sets `thinking_level` and mirrors `enable_thinking` so a rollback reads the right boolean. `AiSystem::thinkingLevel()` falls back to `enable_thinking` when the level is null or not loaded — call it, not the raw attribute.
- `AiSystemManager::thinkingLevels()` drives a host's level selector; `controls_effort = false` means the level only toggles visibility.

### Audio Systems

`Services/Audio/` (`SpeechService`, `TranscriptionService`) wraps laravel/ai's `Audio` and `Transcription` facades, each with `forSystem()` / `forFeature()` like `AgentFactory`. They exist because the facades alone would read credentials from the host's `ai.providers.*` config instead of the `AiSystem` record.

- **`openai-compatible` cannot serve audio roles.** The driver behind `lm-studio` and `openai-compatible` implements neither `AudioProvider` nor `TranscriptionProvider`, so a local-chat deployment always needs a separate cloud record for voice. `AiProvider::supportsRole()` encodes the matrix, `AiSystemManager` rejects a bad pair at validation, and `AiSystemRoleTest` checks the matrix against the laravel/ai provider classes — a dependency upgrade that moves a gateway fails there.
- **ElevenLabs' driver key is `eleven`, not `elevenlabs`** (`toLaravelAiDriver()` translates, as it does `grok` → `xai`).
- **One resolver.** `Services/AiSystemResolver` applies the exists / active / role guards for the audio services *and* `AgentFactory::systemForFeature()`, which delegates to it with `AiSystemRole::Chat`. A role that is not loaded (partial select, unmigrated host) is refused there with a readable error; `AgentFactory::forSystem()` is deliberately looser and refuses only a role known to be non-chat. The factory's two original exception messages are public and pinned by `AiSystemResolverTest`.
- **`model` and `max_tokens` are optional for voice roles only.** The columns are not nullable, so the manager stores a blank model as `''` and the services pass `$system->model ?: null` so the provider default applies.
- **Deliberately absent:** `AiLlmMessage` logging and usage/cost tracking for audio. `ai_llm_messages.ai_conversation_id` is non-nullable and a direct audio call has no conversation; pricing is token-based and voice is billed per character or minute.
- Audio calls are wrapped in `RawExchangeRecorder::capture()`, but capture still depends on `raw_exchanges.providers` (default `lm-studio`) and on the frame having a base URL — which is why `providers.elevenlabs.base_url` has a config default.

### Tool System

Tools are `laravel/mcp` `Tool` subclasses. See `Services/Mcp/Tools/ChatBot/ScanMemoriesTool.php` as the reference implementation.

- Extend `Laravel\Mcp\Server\Tool`, declare `#[Name]` / `#[Description]` attributes, define inputs in `schema(JsonSchema $schema): array`, and implement `handle(Request $request): Response|ResponseFactory`.
- Declare dependencies as constructor parameters — they are resolved via `makeWith()` overrides (see Tool Context below).
- Register host-app tool directories in `AppServiceProvider` via `CodeTalkerServiceProvider::addToolDirectory(path, namespace)`.
- Inject extra DI parameters per conversation via `CodeTalkerServiceProvider::registerToolParameterResolver(callable)`.
- `ChatBotToolRegistry` auto-discovers tools from the package's `Services/Mcp/Tools/ChatBot/` directory plus registered directories, then filters by `AiSystem::allowed_tools`.
- During chat, registry tools are wrapped in `BridgedTool` adapters and executed by laravel/ai's agentic loop (up to 6 steps). `ToolResultConverter` normalizes the `Response` back to an array.
- **Discovery recurses.** `DiscoversAiToolHandlers` walks tool directories with `File::allFiles()`, so a tool's helper classes may be colocated in a subdirectory (as `SearchWebTool` does in `Tools/ChatBot/SearchWeb/`) — but none of them may extend `Tool` or implement `AiToolHandlerContract`, or they will register themselves as phantom tools. `ChatBotToolRegistryTest` pins the discovered tool set.

`SearchWebTool` is the reference for a tool too big for one file: it delegates to `SearchWeb/`, where a `SearchEngine` contract has one implementation per engine (each owning its own endpoints and its API-vs-scrape choice), alongside `SearchEngineRegistry`, the `SearchQuery`/`SearchResult`/`EngineResults` value objects, `SearchHttpClients`, `HtmlResultParser`, `ResultUrlNormalizer`, and `SearchResultsMarkdown`.

### Remote MCP Tools

`Services/Mcp/Remote/` lets an AiSystem use tools hosted on external MCP servers (`AiMcpServer` rows, managed by `AiMcpServerManager`). Each remote tool is its own registry entry, named `{slug}__{remote-name}` (`ExposedToolName`). That one string is the wire name, the registry key, and the `allowed_tools` grant.

- **The registry must never make a network call.** It is built per turn, per operator run, *and* by `AiPersonaManager`/`AiOperatorManager` for admin listings. `RemoteToolCatalog` reads `ai_mcp_server_tools`, which `RemoteToolCatalogSync` fills (on manager create/update, `ai:sync-mcp-servers`, daily). Adding a live `tools()` listing to the registry path would stall admin pages and make a down server cost every turn a timeout.
- **`RemoteToolCatalog` short-circuits** when no granted name contains `__`, so ordinary systems (and the tests that build a registry without migrations) cost no query. It also swallows `QueryException`, so a host that upgraded but has not yet migrated still runs turns.
- **`RemoteToolHandler::handle()` must never throw.** laravel/ai's `InvokesTools` calls `handle()` without a try/catch, so an exception ends the turn. Transport failures are logged (with URL credentials masked, via `Support\RedactedException`) but reported to the model generically, because their messages carry the server URL.
- **`RemoteServerSession` is a breaker that lasts one turn**: after a transport or protocol failure, later calls fail without touching the network. A `JsonRpcException` on a tool call (the server answering "no") deliberately does not trip it; one raised before the session is connected (a rejected `initialize`) does.
- **Sync deletes, then releases names, then upserts**, all in one transaction inside the try/catch, so `sync()` never throws. Upserting first brings back the unique violation when an exposed name passes to another remote tool (`a.b` / `a/b` both sanitise to `x__a_b`). Listing is bounded by `BoundedToolListing` (`remote_mcp.max_tools` tools and pages), which reaches the client's protected `protocol` through a bound closure — a laravel/mcp upgrade that renames it breaks syncs.
- **Schemas are budget-checked before `SchemaNormalizer`** (size, `$ref` count, depth, and node count once refs are expanded — the last is what catches a small fan-out schema that expands to millions of nodes). `RemoteToolHandler` converts once and caches; `RemoteBridgedTool` reads that cache.
- **Remote tools bridge through `RemoteBridgedTool`, not `BridgedTool`.** `BridgedTool::schema()` converts each property on its own, which loses root `$defs` and throws while the request is built. `RemoteToolSchema` converts from the root with laravel/ai's `SchemaNormalizer`, and throws rather than returning an empty schema. Sync marks non-convertible tools `representable = false`.
- In tests, `FakeMcpServer` (`tests/Support/`) fakes the protocol over `Http::fake()`. Use its `down()`/`respondWith()` rather than calling `Http::fake()` again for the same URL: the first matching stub wins, and every stub runs (so a later throwing stub "works" by accident).

Legacy: `Contracts/Mcp/AiToolHandlerContract` (`name()`, `description()`, `schema()`, `handle()`) is still supported — `ChatBotToolRegistry::dispatch()` branches on `instanceof Tool` — but do not write new tools against it.

### Tool Context & Transports

`Support/ToolContext` carries the identity a tool runs under, and is populated differently per transport:

- **Local chat loop** — `ToolContext::forConversation($conversation)`: conversation, `userId`, `visitorEmail`, `feature`.
- **External MCP server** — `ToolContext::forUser($userId)` from the authenticated caller; `conversation` is `null`. Bound as the container default in `CodeTalkerServiceProvider::register()`.

Discovery passes the conversation's context to **every constructor parameter typed `ToolContext`**, whatever its name. A contextual binding was rejected: it has no removal API, so it would hand this conversation's context to a later resolution of the same class on the MCP transport.

Tools must degrade gracefully when `conversation` is null — read only what they need, and use `hasIdentity()` to check for a user identity before touching user-scoped data.

Tool visibility is gated by two independent mechanisms: `shouldRegister()` applies **only on the MCP transport** (laravel/mcp consults it when listing tools), while the local chat loop gates on `AiSystem::allowed_tools`.

### External MCP Server

`Mcp/Servers/CodeTalkerServer` exposes the chat-bot tools (`FetchWebPageTool`, `SearchWebTool`, `ScanMemoriesTool`) to outside MCP clients. Registered by `CodeTalkerServiceProvider::registerMcpServers()`, **disabled by default**. Config under `code-talker.mcp`:

- `enabled` — master switch (default `false`)
- `web.{enabled,path,middleware}` — HTTP transport, default path `mcp/code-talker`, default middleware `auth:sanctum`. Must stay authenticated: the caller is mapped to the `ToolContext` that scopes user data. `registerMcpServers()` falls back to `['auth:sanctum']` when the key is missing or null (a cached config that predates it); only an explicit `[]` opts out.
- `local.{enabled,handle}` — stdio transport, run via `php artisan mcp:start {handle}`

### Raw Provider Exchange Logging

`Services/RawExchange/` (`RawExchangeContext`, `RawExchangeRecorder`, `RawExchangeFrame`, `TeeingStream`) captures the verbatim request and response bytes of every laravel/ai HTTP call into the `ai_provider_exchanges` table. Config under `code-talker.raw_exchanges`:

- `enabled` (default `true`)
- `providers` — comma-separated allow-list of `AiSystem` provider values, or `all` (default `lm-studio`)
- `retention_days` (default `14`) — enforced by `ai:prune-provider-exchanges`

### Memory System

`AiMemoryService` runs post-conversation (via `ProcessAiMemoryJob`) to extract reusable insights using the same `AiSystem`. Memories (`AiFeatureMemory`) are scoped by `user_id` or `visitor_email` and categorized as `preference`, `domain_knowledge`, or `system_tuning`. They are injected into the system prompt under a `## Learned Insights` section.

**A memory belongs to exactly one identity.** The user id wins; a memory written for a user stores `visitor_email = null`, and the visitor scope adds `whereNull('user_id')` so legacy dual-scoped rows never match a visitor. A conversation with neither identity, and every operator run, is never analysed — the observer skips the dispatch, `processCompletedConversation()` returns early, and `AiFeatureMemory::scopeForIdentity()` / `applyMemoryOperations()` throw rather than build an unscoped query (which would touch every memory for the feature).

**Completion is the trigger, and it is inferred.** Nothing in the chat request path can know a conversation is over — the browser just stops sending. `ai:complete-idle-conversations` (scheduled every 15 min) marks `Active` conversations `Completed` after `conversations.idle_timeout_minutes` of no non-system message; `AiConversationObserver` sees that transition and dispatches `ProcessAiMemoryJob` **once per conversation**. Do not re-add a per-turn dispatch: `analyzeConversation()` sends the whole transcript every call, so per-turn extraction costs O(N²) tokens in conversation length.

The command updates rows one at a time on purpose — a mass `update()` bypasses the observer and memory extraction never fires. It iterates with `eachById()`: offset paging skips rows, because each update drops a row out of the query.

### Feature/Default System

`AiSystemFeatureDefault` maps feature keys (e.g., `chat-bot:my-bot`) to a default `AiSystem`. Use `AgentFactory::forFeature('some-feature')` to get a configured agent without referencing an `AiSystem` directly.

### Routes

The package registers **no admin routes**. Management is done through `Services/Management/` (see below); host apps build their own admin screens against those services.

The package ships no route files. Hosts define their own routes against the services.

### Scheduled Jobs

The package auto-registers these scheduled jobs (disable via `code-talker.schedule = false`):
- `ai:sync-conversation-usage` — twice daily
- `BackfillConversationUsageJob` — daily at 02:30
- `ai:prune-provider-exchanges` — daily at 03:00
- `ai:complete-idle-conversations` — every 15 minutes (drives memory extraction)
- `ai:sync-mcp-servers` — daily at 03:30 (refreshes remote MCP tool catalogs)

### Key Config (`config/code-talker.php`)

- `user_model` — host app's User model class
- `middleware` / `admin_middleware` — retained only for hosts still loading a published copy of a removed package route file
- `feature_keys` — valid feature keys for `AiSystem` defaults; empty means unrestricted
- `reserved_slugs` — slugs blocked from root-path chatbots
- `schedule` — set `false` to skip the package's scheduled jobs
- `conversations.idle_timeout_minutes` — inactivity before a conversation is marked `Completed` (triggers memory extraction)
- `mcp.*` — external MCP server (off by default); web/local transports
- `raw_exchanges.*` — verbatim provider request/response capture and retention
- `remote_mcp.*` — remote MCP tool master switch and timeout default/cap (separate from `mcp.*`, which is this package's own server)
- `providers.*` — fallback base URLs, Anthropic `api_version`, LM Studio `server_url`, and token `pricing` for usage tracking; credentials and models live on `AiSystem` DB records

### Model Factories

Package models resolve factories from `Database\Factories\` in the host app (not inside the package). The `CodeTalkerServiceProvider::boot()` wires this via `Factory::guessFactoryNamesUsing`.

## Security analysis

Threat analysis, residual risks, and deployment-specific notes are kept out of this
repository, in the maintainer's local notes. Do not add vulnerability details, attack
paths, or exploitation specifics to this file, the README, the CHANGELOG, commit
messages, or OpenSpec artifacts. Describe the *behavior* a host must react to
("private hosts are now refused unless declared") and nothing more: a public package's
history is read by attackers as readily as by hosts.

## Documentation Maintenance

### Keep planning narrative out of user-facing copy

`openspec/changes/*/proposal.md` and `design.md` are internal planning artifacts — they narrate how a spike's scope moved, what was already shipped when the change was picked up, which alternative was rejected and why. That narrative is legitimate *there*. It means nothing to a reader of the README or CHANGELOG, who never saw the plan it's narrating against and has no baseline to place it in.

A phrase like "already shipped before this change was picked up" or "originally scoped to include X, narrowed after Y landed" answers a question only someone tracking the internal change history would ask. Landing it in the README or CHANGELOG doesn't inform the reader — it confuses them, because it reads as if it should mean something and doesn't.

Before writing README or CHANGELOG copy from a proposal/design doc, restate it cold: describe the shipped behavior on its own terms, not relative to a plan the reader never saw. If a proposal's scope changed mid-flight, that's fine to capture in the proposal — it is not itself a fact worth telling the user.

### README.md

The README is the primary onboarding document for host-app developers installing this package. It should cover these sections in order:

1. **Description** — one-liner matching `composer.json` (`"Multi-provider AI communications package for Laravel…"`)
2. **Requirements** — PHP ^8.3, Laravel ^12.62 || ^13.15; note that provider communication runs on `laravel/ai` and hosts do not need to publish `config/ai.php`
3. **Installation** — `composer require jvjvjv/code-talker`, then publish and run:
   ```bash
   php artisan vendor:publish --tag=code-talker-config
   php artisan vendor:publish --tag=code-talker-migrations
   php artisan migrate
   ```
4. **Configuration** (`config/code-talker.php`) — document `user_model`, `middleware`, `reserved_slugs`, `feature_keys`, `schedule`, and the `providers.*` fallback env vars (base URLs, Anthropic API version, LM Studio server URL, pricing)
5. **AI Systems** — how to create an `AiSystem` record (provider, model, API key, temperature, context length, etc.); how `AgentFactory::forSystem(AiSystem)` and `AgentFactory::forFeature(string)` return configured laravel/ai agents
6. **Chat Bots** — creating an `AiChatBot` with a `prompt_template` (available `{{bot_name}}`, `{{bot_slug}}`, `{{bot_description}}`, `{{visitor_name}}`, `{{visitor_email}}` placeholders), slug, and access path; list the public routes that are auto-registered
7. **Tool Registration** — implementing `AiToolHandlerContract` (`name()`, `description()`, `schema()`, `handle()`); registering a directory with `CodeTalkerServiceProvider::addToolDirectory(path, namespace)`; injecting extra DI parameters per conversation with `CodeTalkerServiceProvider::registerToolParameterResolver(callable)`
8. **Memory System** — how per-user memories are extracted post-conversation via `ProcessAiMemoryJob` and injected into future system prompts; the three `AiFeatureMemory` categories (`preference`, `domain_knowledge`, `system_tuning`) and user scoping by `user_id` / `visitor_email`
9. **Management Services** — `Services/Management/` (`AiSystemManager`, `AiSystemPromptManager`, `AiChatBotManager`, `AiConversationManager`, `AiMemoryManager`); how a host drives them from its own controllers, and how to reuse their `rules()` in a form request
10. **Scheduled Jobs** — what runs automatically (`ai:sync-conversation-usage` twice daily, usage backfill at 02:30); how to disable with `'schedule' => false`

### CHANGELOG.md

Every public release gets one entry at the top of the file. Use this exact format:

```markdown
## [version] — YYYY-MM-DD

Short description of the release (one to two sentences).

### Breaking Changes
- One sentence per change that requires host-app updates (removed config keys, renamed contracts, new required migrations, etc.)

### New Features
- One sentence per user-visible addition.

### Bug Fixes
- One sentence per fix.

### Known Issues
- One sentence per issue.
```

Rules:
- Omit **Breaking Changes** entirely when there are none.
- Omit any section (**New Features**, **Bug Fixes**, **Known Issues**) that has no entries.
- Newest release at the top; oldest at the bottom.
- Version follows semver (`MAJOR.MINOR.PATCH`). Bump MAJOR for breaking changes, MINOR for new features, PATCH for bug fixes only.
- Each bullet describes a user-visible change — not an internal refactor or implementation detail.
- Pre-release / in-progress work is not logged until the version ships. The entry is written once, in the release commit — see **Release process** below.

### Release process

Follow the `cutting-a-release` skill. This repo's settings:

- **Integration branch:** `develop`. **Release branch:** `main`.
- **Release-commit files:** `CHANGELOG.md` and the README "Migrating between versions" row for the same version. Nothing else, and feature commits touch neither.
- **OpenSpec changes carry no CHANGELOG task.** Do not add one when proposing a change; if an older change has one, leave it unticked and defer it to the release commit.
- **Release PR:** `develop` → `main`, titled `Release vX.Y.Z`, merged with a merge commit.
- **Tag command:** `bin/release X.Y.Z`, run on `main` after the merge. It prompts for confirmation, so the user runs it.
- No workflow writes the changelog.

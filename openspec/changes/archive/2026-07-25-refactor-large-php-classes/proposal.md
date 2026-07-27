## Why

Three classes in this package have grown large enough that changing them safely requires reading the whole file first: `ChatBotController` (596 lines) mixes routing, session/cookie state, access control, and two near-duplicate Inertia payload builders; `AiChatBotConversationService` (567 lines) packs transcript assembly, the streaming turn loop with its abort/duration guards, block accumulation, four kinds of persistence, and prompt building into one method plus its private helpers; and `SearchWebTool` (619 lines) hard-codes four search engines, their two fetch strategies each, HTML scraping regexes, URL normalization, and markdown rendering in a single class. Each is edited often (streaming guards, cookie handling, and scraper headers all changed in the last few commits), and each edit currently carries more risk than the change itself warrants.

## What Changes

- Decompose `ChatBotController` into thin HTTP actions that delegate to purpose-built collaborators under `Services/ChatBot/`: conversation session/cookie state, chat-bot access guarding, route-URL resolution, and the Inertia payload builders for the index, show, and by-hash pages.
- Decompose `AiChatBotConversationService` into an orchestrator over collaborators under `Services/ChatBot/Conversation/`: transcript building, request-payload building, turn numbering, system-prompt building, the streaming turn runner, response-block accumulation, and turn persistence/logging. The service keeps `startConversation()` and `continueConversation()` as its public surface.
- Decompose `SearchWebTool` into one class per search engine plus shared value objects and helpers under `Services/Mcp/Tools/ChatBot/SearchWeb/`: a `SearchEngine` contract, `Bing`/`Google`/`DuckDuckGo`/`Brave` engines, an engine registry, HTTP client factories, the HTML result parser, URL normalizer, and the markdown renderer. The tool keeps its `#[Name]`/`#[Description]` attributes, `schema()`, and `handle()`.
- Add regression tests that pin the behavior most at risk from the move: the SSE event sequence emitted by `continueConversation()`, the exact Inertia props rendered by the chat-bot pages, and the exact `Response::structured()` payload of `search-web`.
- **No behavior changes.** The SSE wire format, Inertia prop names and values, tool response payloads, cookie/session semantics, and database writes are all preserved byte-for-byte.
- **No public API changes.** `AiChatBotConversationService`'s constructor signature (5 positional dependencies, in order) and its overridable `streamElapsedSeconds()` / `clientAborted()` guards are preserved, because `tests/Feature/AiChatBotConversationServiceTest.php` subclasses the service anonymously and overrides them. Controller action signatures and `SearchWebTool`'s tool contract are unchanged.

## Capabilities

### New Capabilities
- `php-class-decomposition`: The structural rules for splitting oversized package classes — where extracted collaborators live, what stays on the original class as its preserved public surface, and the behavior-preservation constraints (SSE wire format, Inertia props, tool payloads, tool auto-discovery) any such refactor must satisfy.

### Modified Capabilities
(none — `openspec/specs/` is currently empty, and this change alters no observable behavior, so there are no existing requirements to amend)

## Impact

- **Rewritten**: `src/Http/Controllers/ChatBotController.php`, `src/Services/AiChatBotConversationService.php`, `src/Services/Mcp/Tools/ChatBot/SearchWebTool.php` — each reduced to its public surface plus delegation.
- **New files**: `src/Services/ChatBot/` (session store, access guard, route URLs, page payload builders), `src/Services/ChatBot/Conversation/` (transcript builder, payload builder, turn sequence, prompt builder, turn runner, block accumulator, turn recorder, turn outcome), `src/Services/Mcp/Tools/ChatBot/SearchWeb/` (engine contract, four engines, registry, HTTP clients, HTML parser, URL normalizer, markdown renderer, value objects).
- **Tool auto-discovery**: `DiscoversAiToolHandlers` scans the ChatBot tools directory recursively via `File::allFiles()`. The new `SearchWeb/` subdirectory sits inside that scan, and its classes must not extend `Laravel\Mcp\Server\Tool` or implement `AiToolHandlerContract` so discovery continues to return exactly the current tool set.
- **Removed (internal only)**: the `protected` helpers on `ChatBotController` (`storedState`, `putStoredState`, `historyForBot`, `routeUrlFor`, `abortIfInaccessible`, etc.) and the `private` helpers on `AiChatBotConversationService`. Verified unreferenced anywhere else in `src/` or `tests/`; a host app that subclassed the controller to override them would be affected.
- **Tests**: existing tests must pass unmodified except for import additions. `AiChatBotConversationServiceTest`, `ChatBotCookieTest`, `SearchWebToolTest`, and `RawExchangeChatIntegrationTest` are the primary guardrails.
- **Out of scope**: the next tier of large classes (`AiMemoryService` 339, `Admin/AiSystemController` 320, `ConversationUsageService` 268, `ReadProviderExchangeCommand` 260) is deliberately deferred to a follow-up change. React components are not in this repository — they live in the `jasonvertucio.com` host app, where the chat-component refactor already shipped.
- **No** database, migration, route, config, or dependency changes.

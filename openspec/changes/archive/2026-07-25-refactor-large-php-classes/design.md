## Context

Three classes carry most of this package's day-to-day edit risk:

| File | Lines | What is bundled together |
| --- | --- | --- |
| `src/Services/Mcp/Tools/ChatBot/SearchWebTool.php` | 619 | 4 engines × 2 fetch strategies, scraping regexes, URL normalization, markdown rendering, HTTP client config |
| `src/Http/Controllers/ChatBotController.php` | 596 | 10 actions, session/cookie state machine, access guard, route-URL resolution, 3 Inertia payload builders (2 near-duplicates) |
| `src/Services/AiChatBotConversationService.php` | 567 | transcript assembly, continuation loop, abort/duration guards, block accumulation, 4 kinds of persistence, prompt building |

The package already has a convention for this: behavior that belongs together lives in a domain-named subnamespace under `Services/` (`LaravelAi/`, `RawExchange/`, `Mcp/`), value objects are `final` with promoted `public readonly` properties and named static constructors (`ToolContext::forConversation()`, `RawExchangeFrame::forSystem()`), and services take their dependencies as promoted `private` constructor properties. There is no `Actions/` or `Data/` namespace, and this change does not introduce one.

Two hard constraints come from the existing tests:

1. `tests/Feature/AiChatBotConversationServiceTest.php` builds the service with `new class(AgentFactory, AiMemoryService, ConversationUsageService, RawExchangeContext, AiSystemProviderConfigurator) extends AiChatBotConversationService` and overrides `streamElapsedSeconds()` / `clientAborted()`. The constructor's positional signature and those two `protected` hooks are frozen — and the overrides must still be the ones the streaming loop calls.
2. `StreamTranslator`'s Anthropic-shaped SSE output is documented in `CLAUDE.md` as a compatibility surface with the host-app browser UI. Nothing in the emitted byte stream may move.

## Goals / Non-Goals

**Goals:**
- No class over ~150 lines among the three targets, each remaining class doing one job.
- Zero observable behavior change: same SSE bytes, same Inertia props, same tool payload, same DB writes, same cookies.
- Every extracted piece independently readable and, where it has logic worth pinning, independently testable.
- New structure discoverable by convention, so the next large class gets split the same way.

**Non-Goals:**
- No new abstractions for their own sake — no repository layer, no event bus, no DTO library.
- No de-duplication that changes behavior. `show()` and `showByHash()` build *almost* the same payload; their differences (`chatHash` present only in the latter, `showIdentityForm` computed from different inputs) are preserved deliberately, not "cleaned up".
- No changes to `AiMemoryService`, `Admin/*Controller`, `ConversationUsageService`, `ReadProviderExchangeCommand`, or `FetchWebPageTool` — a follow-up change.
- No shared-helper extraction between `SearchWebTool` and `FetchWebPageTool`, even though both do HTML cleanup. Touching `FetchWebPageTool` widens the blast radius for no gain here.

## Decisions

### 1. Guards stay on the service; the turn loop takes them as a collaborator

The streaming loop is the biggest single block in `AiChatBotConversationService` and the obvious extraction. But moving it to a `ConversationTurnRunner` naively would break the tests: an anonymous subclass overriding `clientAborted()` would no longer be consulted, because the runner would have its own copy.

The loop is extracted, and the guards are passed *in* as a `TurnGuards` value object the service builds from its own protected methods:

```php
// AiChatBotConversationService — the protected hooks stay exactly as they are
protected function streamElapsedSeconds(float $startedAt): float { return microtime(true) - $startedAt; }
protected function clientAborted(): bool { return connection_aborted() !== 0; }

// ...and are handed to the runner as closures bound to $this, so a subclass's
// override is what the loop actually calls.
$guards = new TurnGuards(
    elapsedSeconds: fn (float $startedAt): float => $this->streamElapsedSeconds($startedAt),
    clientAborted: fn (): bool => $this->clientAborted(),
);
```

*Alternatives considered:* (a) making the runner itself subclassable and updating the tests — rejected, the user's constraint is that tests keep passing unmodified; (b) injecting a `Clock` interface — rejected, it changes the constructor signature, which is frozen; (c) leaving the loop inline — rejected, it is the main thing making the file unreadable.

### 2. New collaborators are constructed internally, not injected

`SystemPromptBuilder` needs `AiMemoryService`; `TurnRecorder` needs `ConversationUsageService`. Both are already injected into `AiChatBotConversationService`, so the collaborators are built from those existing properties rather than added as constructor parameters. This keeps the 5-argument signature intact while still giving each collaborator its dependency explicitly.

### 3. `ChatBotController` keeps its actions; state moves to a session store

The controller's ten actions stay as actions — Laravel convention, and the routes bind to them. What moves is everything they lean on:

| New class (`Services\ChatBot\`) | Absorbs |
| --- | --- |
| `ConversationSessionStore` | `storedState`, `putStoredState`, `rememberConversation`, `clearStoredState`, `stateKey`, `storedConversation`, `forgetLegacyCookies`, and the `COOKIE_MINUTES` / `CURRENT_COOKIE` / `MAX_HISTORY` / `LEGACY_COOKIE_PATTERN` constants |
| `ChatBotAccessGuard` | `abortIfInaccessible`, `requestAccessPath` |
| `ChatBotRouteUrls` | `routeUrlFor` and the root-vs-chat prefix choice |
| `ConversationHistoryPresenter` | `historyForBot` |
| `ChatBotPagePayload` | the shared `ai/ChatBot` prop array, with the two differing inputs (`chatHash` inclusion, `showIdentityForm`) passed in by the caller |
| `ChatBotIndexPayload` | the bot + conversations mapping in `index()` |
| `ChatBotStatusResolver` | the per-system status memoization in `statuses()` |
| `ChatStreamResponse` | the `response()->stream()` closure, `ignore_user_abort`, the `request_received` preamble, the flush loop, and the error fallback |

`ConversationSessionStore` is the one with real state-machine logic (legacy cookie cleanup, ownership check, history cap) and gets its own unit-level tests beyond the existing `ChatBotCookieTest`.

*Alternative considered:* one invokable action class per endpoint (`ShowChatBotAction`, etc.). Rejected — the package has no such convention anywhere, and with the state extracted the actions are 5–20 lines each, so the extra indirection would cost more than it saves.

### 4. `continueConversation()` becomes an orchestrator over a linear pipeline

`continueConversation()` stays a `Generator` and keeps yielding — it cannot delegate wholesale, because the yields must flow through. It reads as: build transcript → build agent → `yield from` the runner → record → finish.

| New class (`Services\ChatBot\Conversation\`) | Absorbs |
| --- | --- |
| `ConversationTranscript` (value object) | the `foreach ($allMessages …)` split into `?string $systemPrompt` + `Message[] $history`, including the skip rules for the just-saved user message and blank assistant turns |
| `TranscriptBuilder` | building the above from a conversation + the persisted user message |
| `RequestPayloadBuilder` | `buildRequestPayload` |
| `TurnSequence` | `getTurnNumberForConversation` |
| `SystemPromptBuilder` | `buildSystemPrompt` / `buildSystemPromptForBot`, template placeholders, and the `## Learned Insights` assembly |
| `ConversationTitle` | `titleFromUserMessage` |
| `ResponseBlocks` | the `$appendToBlocks` closure plus `text()` / `reasoning()` / `toArray()` accessors over the accumulated blocks |
| `TurnGuards` (value object) | the two closures from Decision 1 |
| `ConversationTurnRunner` | the `for ($attempt …)` continuation loop: raw-exchange push/pop, event logging, `StreamStart` budget reset, unrecoverable `ErrorEvent`, abort/duration checks, tool-call collection, translation + yielding, per-attempt `AiLlmMessage` rows, and the `length` re-prompt |
| `TurnOutcome` (value object) | `clientAborted`, `maxDurationExceeded`, `maxDurationMessage`, `durationMs` returned from the runner |
| `TurnRecorder` | the assistant `AiConversationMessage`, the success/error `AiInteractionLog`, the usage sync, and the provider-error logging in the `catch` |

The runner is a generator that yields SSE strings and returns a `TurnOutcome`, so the service does `$outcome = yield from $this->turnRunner->run(...)`. This keeps the yield ordering identical without the service re-implementing the loop.

*Note on ordering:* the `finally { $this->rawExchangeContext->pop(); }` and the fact that the response `AiLlmMessage` is written *after* the inner `foreach` breaks are load-bearing. They move into the runner verbatim.

### 5. `SearchWebTool` gets one class per engine behind a contract

| New file (`Services\Mcp\Tools\ChatBot\SearchWeb\`) | Role |
| --- | --- |
| `SearchEngine` (interface) | `key(): string`, `search(SearchQuery $query): EngineResults` |
| `BingSearchEngine`, `GoogleSearchEngine`, `DuckDuckGoSearchEngine`, `BraveSearchEngine` | one per engine; each owns its API-vs-web strategy choice, its endpoints, and its response mapping |
| `SearchEngineRegistry` | supported keys, key→engine resolution, validation of the requested subset |
| `SearchQuery` (value object) | query, per-engine limit, page, derived `offset()` / `start()` |
| `SearchResult` (value object) | title, url, description |
| `EngineResults` (value object) | source, queryUrl, results, error; `::failed()` replaces `httpErrorResult` |
| `SearchHttpClients` | `webHttpClient()` / `apiHttpClient()`, holding the scraper headers and the `WebScraperUserAgent` call |
| `HtmlResultParser` | the four regex patterns, `cleanHtmlText`, result capping |
| `ResultUrlNormalizer` | `normalizeResultUrl`, `normalizeGoogleUrl`, the DuckDuckGo `uddg` unwrap, scheme allow-list |
| `SearchResultsMarkdown` | `renderMarkdown`, `escapeMarkdownText` |

`SearchWebTool::handle()` is left as: validate input → resolve engines → run each through the registry with per-engine try/catch → assemble the structured response.

**Discovery safety:** `DiscoversAiToolHandlers::discoverHandlers()` walks the tools directory with `File::allFiles()`, which recurses, so every file in `SearchWeb/` is visited. It is safe because the trait skips anything that is not a `Tool` subclass or `AiToolHandlerContract` implementation *before* instantiating it — so none of these classes may extend `Tool`. A test asserts the discovered tool-name set is unchanged.

*Alternative considered:* putting the engines in a top-level `Services\WebSearch\` namespace, outside the scanned directory. Rejected — colocation is what makes the tool readable, and the discovery filter already handles it. The constraint is recorded as a spec requirement so it does not get lost.

### 6. Characterization tests are written before the code moves

The existing suite covers the streaming guards and cookie behavior well, but not the exact Inertia prop set, the full SSE event sequence for a normal turn, or the exact `search-web` payload. Those three get characterization tests written and passing against the *current* code first, so any drift during the move fails immediately rather than at review time.

## Risks / Trade-offs

- **A subclass override stops being consulted, silently.** → Decision 1's closure indirection, plus the existing guard tests are the acceptance gate; they must pass unmodified, and they are run after the runner extraction specifically.
- **Yield ordering changes when the loop moves into a generator.** → `yield from` preserves ordering by construction; the new full-sequence SSE characterization test (Decision 6) pins it, and `RawExchangeChatIntegrationTest` pins the push/pop nesting.
- **The two Inertia payloads get accidentally unified.** → Their differences are written into the spec as separate scenarios, and the payload builder takes them as explicit parameters rather than inferring them.
- **A `SearchWeb/` class accidentally extends `Tool` and registers itself as a phantom tool.** → Spec requirement plus a discovery test asserting the exact tool-name set.
- **Host apps that subclassed `ChatBotController` to override its `protected` helpers break.** → Verified unreferenced within this package; called out in the proposal's Impact, and worth a CHANGELOG note when this ships.
- **Cost: ~30 new files.** Accepted deliberately — the "aggressive, one class per responsibility" depth was the chosen trade-off. Colocated namespaces keep each cluster findable.

## Migration Plan

Ship as one change, in three independently revertable commits (one per target class), each ending with the full suite green. Characterization tests land first, in their own commit, so they are also a standalone safety net if any stage is rolled back. No data, config, or route migration is involved.

## Open Questions

- Should the removal of `ChatBotController`'s `protected` helpers be called a breaking change in `CHANGELOG.md`? They are undocumented internals, but a host app *could* have subclassed them. Leaning toward a note under an internal-changes line rather than **Breaking Changes**, since the README never presents the controller as an extension point.

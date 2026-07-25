## 1. Baseline and characterization tests

- [x] 1.1 Run `composer test` and record the passing baseline (test count + assertions) to compare against after every stage — **baseline: 109 tests, 346 assertions**
- [x] 1.2 Add `tests/Feature/ChatBotPagePropsTest.php` pinning the exact Inertia component + prop set for `index()`, `show()` (session-backed conversation, and none), and `showByHash()` — including that `chatHash` appears only in the by-hash payload and that `showIdentityForm` is derived differently in each
- [x] 1.3 Extend `tests/Feature/AiChatBotConversationServiceTest.php` with a full-sequence SSE assertion for a normal completed turn: the `model_loading` status line, the translated deltas in order, and the trailing `data: [DONE]`
- [x] 1.4 Extend `tests/Feature/SearchWebToolTest.php` to snapshot the complete `Response::structured()` payload (all eight top-level keys) and the rendered markdown for a faked multi-engine search, plus the single-engine-failure shape
- [x] 1.5 Add a discovery test asserting `ChatBotToolRegistry` resolves exactly the current tool-name set (guards the upcoming `SearchWeb/` subdirectory)
- [x] 1.6 Confirm 1.2–1.5 pass against the current, unmodified code, then commit them alone

## 2. SearchWebTool decomposition

- [x] 2.1 Create `src/Services/Mcp/Tools/ChatBot/SearchWeb/` with the value objects: `SearchQuery` (query, limit, page, `offset()`, `start()`), `SearchResult` (title, url, description), and `EngineResults` (source, queryUrl, results, error) with an `EngineResults::failed()` named constructor replacing `httpErrorResult()`
- [x] 2.2 Add `ResultUrlNormalizer` (`normalizeResultUrl`, `normalizeGoogleUrl`, DuckDuckGo `uddg` unwrap, http/https scheme allow-list) and `HtmlResultParser` (the four engine regexes, `cleanHtmlText`, result capping) — moved verbatim
- [x] 2.3 Add `SearchHttpClients` with `webHttpClient()` / `apiHttpClient()`, taking `ToolContext` so the `WebScraperUserAgent::forBotName()` header is unchanged
- [x] 2.4 Define the `SearchEngine` interface (`key()`, `search(SearchQuery): EngineResults`) and implement `DuckDuckGoSearchEngine` first, since it has a single fetch strategy
- [x] 2.5 Implement `BingSearchEngine`, `GoogleSearchEngine`, and `BraveSearchEngine`, each keeping its own API-key check, endpoint config lookups, and API-vs-web strategy choice
- [x] 2.6 Add `SearchEngineRegistry` owning the supported-engine list, key→engine resolution, and the unsupported-engine validation message
- [x] 2.7 Add `SearchResultsMarkdown` (`render()`, `escapeMarkdownText()`) producing byte-identical markdown, including the trailing "continue searching" block
- [x] 2.8 Rewrite `SearchWebTool` to input validation → registry resolution → per-engine try/catch with the existing `Log::warning` → structured response assembly, keeping its attributes, `schema()`, `handle()`, and single-`ToolContext` constructor
- [x] 2.9 Verify no class under `SearchWeb/` extends `Laravel\Mcp\Server\Tool` or implements `AiToolHandlerContract`, then run `composer test` — 1.4 and 1.5 must pass unchanged

## 3. ChatBotController decomposition

- [x] 3.1 Create `src/Services/ChatBot/ConversationSessionStore` absorbing `storedState`, `putStoredState`, `rememberConversation`, `clearStoredState`, `stateKey`, `storedConversation`, `forgetLegacyCookies` and the four cookie/history constants
- [x] 3.2 Add `tests/Feature/ConversationSessionStoreTest.php` covering the legacy-cookie sweep, the 25-entry history cap, the cookie flags (http-only, lax, 180 days, secure-follows-scheme), and the foreign-`user_id` discard path
- [x] 3.3 Add `ChatBotAccessGuard` (`abortIfInaccessible`, `requestAccessPath`) and `ChatBotRouteUrls` (`routeUrlFor` with the root-vs-chat prefix choice)
- [x] 3.4 Add `ConversationHistoryPresenter` absorbing `historyForBot`, preserving the `is_current` comparison, the null-conversation filter, and the `diffForHumans()` fallback chain
- [x] 3.5 Add `ChatBotPagePayload` building the shared `ai/ChatBot` props, taking `chatHash` inclusion and `showIdentityForm` as explicit parameters so `show()` and `showByHash()` keep their existing differences
- [x] 3.6 Add `ChatBotIndexPayload` (bot + conversations mapping, authenticated-user-only conversation lookup) and `ChatBotStatusResolver` (per-`ai_system_id` memoization behind the slug-keyed result)
- [x] 3.7 Add `ChatStreamResponse` wrapping the `response()->stream()` closure: `ignore_user_abort(true)`, the `request_received` preamble, the `ob_flush`/`flush` loop, the `X-Chat-Hash` header and SSE headers, and the throwable fallback that emits an error plus `[DONE]`
- [x] 3.8 Rewrite `ChatBotController` to constructor-inject the new collaborators alongside the existing `AiChatBotConversationService` and `AiModelReadinessService`, reducing each action to delegation and removing the now-empty `protected` helpers
- [x] 3.9 Run `composer test` — `ChatBotCookieTest` and the 1.2 prop test must pass unchanged

## 4. AiChatBotConversationService decomposition

- [x] 4.1 Create `src/Services/ChatBot/Conversation/` with `ConversationTranscript` (systemPrompt + history) and `TranscriptBuilder`, preserving the skip rules for the just-persisted user message and for blank assistant turns
- [x] 4.2 Add `RequestPayloadBuilder` (`buildRequestPayload`), `TurnSequence` (`getTurnNumberForConversation`), and `ConversationTitle` (`titleFromUserMessage`), all moved verbatim
- [x] 4.3 Add `SystemPromptBuilder` absorbing `buildSystemPrompt` / `buildSystemPromptForBot`, constructed inside the service from the already-injected `AiMemoryService` so the 5-argument constructor is untouched
- [x] 4.4 Add `ResponseBlocks` replacing the `$appendToBlocks` closure, with `append()`, `text()`, `reasoning()`, and `toArray()` reproducing the same run-merging and the same `implode`/`implode("\n\n")` joins
- [x] 4.5 Add `TurnGuards` holding `elapsedSeconds` and `clientAborted` closures, and `TurnOutcome` carrying `clientAborted`, `maxDurationExceeded`, `maxDurationMessage`, `durationMs`
- [x] 4.6 Add `ConversationTurnRunner` as a generator that yields SSE strings and returns a `TurnOutcome`, moving the continuation loop verbatim: raw-exchange push with `finally` pop, per-event debug log, `StreamStart` budget reset, unrecoverable `ErrorEvent` throw, guard checks in their current order, tool-call collection, translation and yielding, the per-attempt request/response `AiLlmMessage` rows, and the `length` re-prompt with `agent->append()`
- [x] 4.7 Add `TurnRecorder` for the post-loop writes: the assistant `AiConversationMessage` (including the reasoning-only case), the pricing snapshot, the success/error `AiInteractionLog` with its `max_stream_duration` metadata, the usage sync, and the provider-error `catch` writes
- [x] 4.8 Rewrite `continueConversation()` as an orchestrator using `yield from $runner->run(...)`, keeping `startConversation()`, the constructor signature, and the `protected streamElapsedSeconds()` / `clientAborted()` hooks exactly as they are — with the guards passed to the runner as closures bound to `$this`
- [x] 4.9 Run `composer test` — every existing `AiChatBotConversationServiceTest` case, including the anonymous-subclass guard overrides, must pass with zero edits to that file
- [x] 4.10 Confirm `RawExchangeChatIntegrationTest` still passes, proving the frame push/pop nesting survived the move

## 5. Verification and wrap-up

- [ ] 5.1 Run the full suite and confirm the test count and assertion count match or exceed the 1.1 baseline, with no skipped or incomplete tests
- [ ] 5.2 Confirm the three target files are each under ~150 lines and that no new class exceeds it
- [ ] 5.3 Grep `src/` and `tests/` for references to the removed `protected`/`private` helper names to confirm none remain
- [ ] 5.4 Manually exercise a live chat turn against a configured `AiSystem` to confirm the browser stream, reasoning display, and cost readout are visually unchanged
- [ ] 5.5 Update `CLAUDE.md`'s Architecture section to name the new `Services/ChatBot/`, `Services/ChatBot/Conversation/`, and `SearchWeb/` namespaces so future work follows the same structure
- [ ] 5.6 Add a CHANGELOG entry for the next release noting the internal restructure and the removal of `ChatBotController`'s `protected` helpers as a host-app extension point

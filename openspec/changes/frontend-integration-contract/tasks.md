## 1. Document the contracts

- [x] 1.1 Re-derive the emitted SSE event set from source (`StreamTranslator::translate()`/`finish()`, the two `yield` sites in `AiChatBotConversationService`, and the preamble/fallback in `ChatStreamResponse`) and confirm the list matches the design doc before writing anything down
- [x] 1.2 Add a **Frontend Integration** section to `README.md` between "Chat Bots" and "Tool Registration", opening with the endpoints a chat UI calls (`POST messages`, `GET status`, `POST warmup`, `POST reset`, `POST switch`) and which are Inertia vs JSON vs SSE
- [x] 1.3 Document the `ai/ChatBot` props as a table — `bot.{name,description,require_visitor_identity,total_cost_usd}`, `messages[].{role,content,reasoning_content,blocks}`, `history[].{handle,label,is_current,is_stale,updated_at,cost_usd}`, `messageUrl`, `resetUrl`, `switchUrl`, `statusUrl`, `warmupUrl`, `chatUrl`, `chatUrlBase`, `showIdentityForm` — and call out that `chatHash` appears only on the hash-linked page and that `showIdentityForm` is derived differently on each
- [x] 1.4 Document the `ai/ChatBotsIndex` props, including the nested `conversations[].{title,updated_at,updated_at_human,is_stale}` and that conversations are populated only for an authenticated user
- [x] 1.5 Document the SSE stream: every event type with its payload, that each frame is `data: <json>\n\n`, that a successful turn ends with `data: [DONE]\n\n`, that a `max_stream_duration` error is **not** followed by `[DONE]`, and that the response carries `X-Chat-Hash` — **corrected while verifying: *no* error frame is followed by `[DONE]`, not just the max-duration one; pinned by `test_only_a_finished_turn_is_terminated_with_done`**
- [x] 1.6 State that both contracts are public API covered by the package's semver, and note the `status`-frame/`message_start` overlap as a known wart rather than blessing it as intentional design
- [x] 1.7 Verify the README claims against the code one more time — particularly the `[DONE]` asymmetry on the max-duration path, which is easy to get backwards

## 2. Configurable component names

- [x] 2.1 Add `inertia.components` as a **new top-level key** in `config/code-talker.php` with `chat_bot` => `ai/ChatBot` and `chat_bots_index` => `ai/ChatBotsIndex`, documented in the file's comment style. It must not be nested under an existing top-level key: `mergeConfigFrom` is a shallow `array_merge`, so a host's already-published copy of an existing block would replace the package's wholesale and silently drop the new subkey
- [x] 2.2 Read the component names with an **inline default** — `config('code-talker.inertia.components.chat_bot', 'ai/ChatBot')` — in `ChatBotController::index()`, `show()`, and `showByHash()`, matching the convention every other nested config read in `src/` already follows. This is load-bearing, not stylistic: a host that published its config before this key existed and then ran `config:cache` skips `mergeConfigFrom` entirely, so without the inline default the component name resolves to `null` in production only
- [x] 2.3 Extend `tests/Feature/ChatBotPagePropsTest.php` to assert the defaults render `ai/ChatBot` and `ai/ChatBotsIndex`, that overriding each config key changes the rendered component while leaving every prop key and value untouched, and — covering the cached-config case — that **unsetting the `code-talker.inertia` key entirely still renders the default components** rather than failing
- [x] 2.4 Document the new config keys in the README's Configuration section
- [x] 2.5 Run `composer test` — all existing tests must pass unchanged

## 3. TypeScript toolchain

- [ ] 3.1 Add a `package.json` (private, no runtime dependencies, `typescript` as the sole devDependency) with a `typecheck` script running `tsc --noEmit`
- [ ] 3.2 Add a `tsconfig.json` in strict mode targeting a DOM lib, scoped to `resources/js`
- [ ] 3.3 Add `node_modules` to `.gitignore`
- [ ] 3.4 Add a `typecheck` job to `.github/workflows/tests.yml` that installs Node and runs the script, kept separate from the PHP matrix so a JS failure is legible on its own

## 4. Published TypeScript types

- [ ] 4.1 Create `resources/js/types/code-talker.d.ts` with the page prop interfaces: `ChatBotPageProps`, `ChatBotHashPageProps` (adding `chatHash`), `ChatBotsIndexProps`, plus `ChatMessage`, `ChatHistoryEntry`, `MessageBlock`, and `ChatBotSummary`
- [ ] 4.2 Add the discriminated SSE union — `StatusEvent`, `MessageStartEvent`, `ContentBlockDeltaEvent`, `ReasoningBlockDeltaEvent`, `MessageDeltaEvent`, `MessageStopEvent`, `ErrorEvent` — as `ChatStreamEvent`, keyed on `type`, with `StopReason` and the `error` `reason` as string-literal unions
- [ ] 4.3 Confirm exhaustiveness: write a throwaway `switch` over `ChatStreamEvent` with a `never` default and check it compiles without a catch-all, then delete it
- [ ] 4.4 Register the `code-talker-types` publish tag in `CodeTalkerServiceProvider`, publishing to `resources/js/types/` in the host app
- [ ] 4.5 Cross-check every declared field against the PHP that produces it — `ChatBotPagePayload`, `ChatBotIndexPayload`, `ConversationHistoryPresenter`, `StreamTranslator` — since a wrong type here is worse than no type

## 5. Published stream client

- [ ] 5.1 Create `resources/js/client/code-talker-stream.ts` exporting `streamChatTurn(url, payload, callbacks)`, importing only browser APIs and the published types
- [ ] 5.2 Implement the SSE read loop over `fetch` + `ReadableStream`, buffering partial chunks so an event split across reads is not dropped — the failure mode most likely to survive casual testing
- [ ] 5.3 Dispatch `onStatus`, `onText`, `onReasoning`, `onDone`, and `onError` from the parsed events, and surface `X-Chat-Hash` through `onChatHash` as soon as headers arrive
- [ ] 5.4 Return `{ abort(), done }` wired to an `AbortController`, so a cancelled turn stops firing callbacks while the server still persists its partial content
- [ ] 5.5 Treat `[DONE]` as the terminator, and make a stream that ends without it (the `max_stream_duration` path) resolve rather than hang
- [ ] 5.6 Ignore unrecognized event types rather than throwing, so a future additive event cannot break an old client
- [ ] 5.7 Register the `code-talker-client` publish tag, publishing to `resources/js/` in the host app
- [ ] 5.8 Run `npm run typecheck` and confirm it passes
- [ ] 5.9 Document both publish commands in the README's Frontend Integration section, stating plainly that the client is a starting point the host owns after publishing, while the types are safe to re-publish on upgrade

## 6. Verification and wrap-up

- [ ] 6.1 Add a PHP test asserting both new publish tags are registered and resolve to files that exist on disk
- [ ] 6.2 Run `composer test` and confirm no regression against the current baseline
- [ ] 6.3 Run `npm run typecheck` clean
- [ ] 6.4 Walk the README's Frontend Integration section end to end as if implementing a UI from scratch, and confirm nothing requires opening package source
- [ ] 6.5 Update `CLAUDE.md` to point at the documented contract as the source of truth for the wire format, replacing the bare "do not change it" note with a reference to the README section and the published types
- [ ] 6.6 Add a CHANGELOG entry when the version is cut — a minor bump, since this is additive: new config keys with backward-compatible defaults, two new publish tags, no behavior change

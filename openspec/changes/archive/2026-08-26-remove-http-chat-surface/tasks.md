## 1. Close the transport leaks

- [x] 1.1 `ConversationTurnRunner` and `AiChatBotConversationService` yield **structured event arrays** instead of pre-encoded SSE strings
- [x] 1.2 Add `Services/ChatBot/SseFrameEncoder`, which owns the `data: <json>\n\n` framing and the `[DONE]` rule — a finished turn is terminated, an `error` event is terminal on its own
- [x] 1.3 Make cancellation injectable via `AiChatBotConversationService::usingCancellationCheck()`, keeping `connection_aborted()` as the default and `clientAborted()` protected so existing subclass overrides still work

## 2. Preserve what only the controller had

- [x] 2.1 Enforce the inactive-bot refusal in `startConversation()`
- [x] 2.2 Enforce `require_visitor_identity` in `startConversation()` — the form request had these as nullable, so the rule existed only in the controller
- [x] 2.3 Call `generateChatHash()` from `continueConversation()`; it migrates a stale hash rather than merely producing a header value
- [x] 2.4 Add `Services/ChatBot/ChatBotPresenter` carrying the transcript query, the per-bot lifetime cost, and the per-user conversation list — the three queries that existed nowhere but the page payloads

## 3. Removal

- [x] 3.1 Delete `ChatBotController`, `SendAiChatBotMessageRequest`, and `routes/codetalker-chatbots.php` (both `src/Http/` and `routes/` are now empty and gone)
- [x] 3.2 Delete `ConversationSessionStore`, `ChatBotAccessGuard`, `ChatBotRouteUrls`, `ConversationHistoryPresenter`, `ChatBotPagePayload`, `ChatBotIndexPayload`, `ChatStreamResponse`
- [x] 3.3 Remove route loading, `routeFilePath()`, and the `code-talker-routes` / `code-talker-chatbot-routes` publish tags from the service provider
- [x] 3.4 Remove the `inertia` config block; keep `middleware` / `admin_middleware` for hosts still loading a published copy of a removed route file
- [x] 3.5 Drop `inertiajs/inertia-laravel` from `composer.json` — with no pages left, nothing uses it, and a package shipping no UI should not force a UI framework on every host

## 4. Keep the frontend contract as a helper

- [x] 4.1 `StreamTranslator` stays; it produces the event vocabulary
- [x] 4.2 `code-talker-types` and `code-talker-client` publish tags stay
- [x] 4.3 Split `code-talker.d.ts`: the Inertia page-prop half is deleted, the transcript shape and the `ChatStreamEvent` union survive
- [x] 4.4 `npm run typecheck` clean

## 5. Tests

- [x] 5.1 Delete `ChatBotPagePropsTest`, `ConversationSessionStoreTest`, `ChatBotCookieTest`
- [x] 5.2 Rewrite `AiChatBotConversationServiceTest`'s frame helper to pipe the turn through `SseFrameEncoder` — every existing assertion, on events and on raw lines, then keeps its meaning and the encoder inherits the characterization coverage. All eleven tests pass with their bodies otherwise untouched
- [x] 5.3 Update `PackageSmokeTest`: assert the package registers **no** routes, that the route publish tags are gone, and that the frontend tags survive
- [x] 5.4 Add `ChatTurnLibraryTest` covering encoding (including both `[DONE]` cases), the two access rules, injectable cancellation, the presenter queries, and chat-hash currency

## 6. Documentation

- [x] 6.1 Replace the README's route/props/endpoint sections with **Driving a turn**, **Turn events**, **Cancellation**, **Resolving conversations across requests**, and **Presentation queries**
- [x] 6.2 Show a complete host controller, including `ignore_user_abort(true)` and per-frame flushing — without them the default cancellation check never fires
- [x] 6.3 Document that conversation resolution across requests is now the host's job, with `findByChatHashOrUuid()` as the lookup
- [x] 6.4 Update `CLAUDE.md`: no routes, the encoder boundary, the relocated access rules, and injectable cancellation
- [x] 6.5 `composer test` and `npm run typecheck` green

## 7. Deferred

- [ ] 7.1 Raw exchange logging (`Services/RawExchange/`, 855 lines, its own table, retention command, and interactive reader) is **retained**. It is a laravel/ai debugging tool with no chatbot coupling and is the strongest candidate for extraction into its own package before any 1.0. No action this release beyond recording the intent

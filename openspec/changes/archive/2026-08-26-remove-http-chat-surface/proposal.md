## Why

Target release: **0.13.0**. Third of three staged changes. Deliberately **not** 1.0 — the package is not ready to commit to a stable API.

The package ships no UI but ships an opinionated HTTP surface: 14 chat routes across two prefixes, cookie and session state, Inertia page rendering, and an SSE response wrapper. A host that wants a different transport — websockets, broadcast, a queue, a CLI — cannot use any of it, and a host that wants a different UI framework still inherits Inertia as a hard `require`.

The turn logic underneath is already transport-neutral. All thirteen classes in `Services/ChatBot/Conversation/` are pure (verified: no `Request`, `Response`, session, cookie, Inertia, redirect, or route-URL reference anywhere in the directory), and `AiChatBotConversationService::startConversation()` has no HTTP awareness at all. Only two leaks separate the service layer from being directly host-drivable.

## What Changes

**The two leaks close first:**

- **Output format.** `continueConversation()` is a generator that yields pre-encoded SSE strings — `'data: ' . json_encode(...) . "\n\n"` at six sites, plus `"data: [DONE]\n\n"`. Turn logic and wire encoding are fused. The generator will yield **structured events**; a thin encoder does the `data:` framing.
- **Cancellation.** `clientAborted()` calls `connection_aborted()`, a PHP-SAPI concept that returns 0 outside a web request — so the guard silently never fires in a queue or CLI context. Cancellation becomes an injectable concern with an HTTP-flavored default.

**Removed:** `ChatBotController`, `SendAiChatBotMessageRequest`, `routes/codetalker-chatbots.php`, and the HTTP-coupled half of `Services/ChatBot/` — `ConversationSessionStore` (204), `ChatBotAccessGuard`, `ChatBotRouteUrls`, `ConversationHistoryPresenter`, and `ChatStreamResponse`. Config keys `middleware` and `inertia.components` go with them.

**Kept as optional helpers**, per the decision to preserve the 0.10.0 frontend work: `StreamTranslator` stays as the PHP helper that turns turn events into the documented Anthropic-shaped frames, and `resources/js` plus the `code-talker-types` / `code-talker-client` publish tags survive. A host that wants the documented SSE contract gets it for free; one that doesn't ignores it. The `.d.ts` needs splitting — its Inertia page-prop half dies with the controller, its `ChatStreamEvent` half describes what the turn generator emits and survives.

**Logic that must find a new home rather than be deleted with its controller:**

- **Turn-start composition** — "resume or create a conversation, then continue it." `continueConversation()` requires an existing `AiConversation`; nothing else composes the two.
- **Chat-hash generation** — `ChatBotController::message()` unconditionally calls `generateChatHash()`, which *migrates* stale hashes. Deleting the call deletes the migration.
- **Visitor-identity enforcement** — `require_visitor_identity` is enforced only in the controller; the form request has `name`/`email` as nullable, and the model attribute has no service-level guard.
- **Access authorization** — the `is_active` half of `ChatBotAccessGuard` is a real domain rule with no non-HTTP equivalent.
- **Presentation queries** — `ChatBotPagePayload`'s visible-transcript mapping (which strips the `system` role) and bot cost aggregate, and `ChatBotIndexPayload`'s per-user conversation grouping. These queries exist nowhere else.

**`ChatBotStatusResolver` is already pure** and simply becomes directly callable, as is `AiModelReadinessService`.

## Capabilities

### Modified Capabilities

- The chat turn becomes a library call yielding structured events rather than an HTTP endpoint yielding SSE text. The event *vocabulary* is preserved — `content_block_delta`, `reasoning_block_delta`, `message_delta`, `message_stop`, `error` — so a host using the published client keeps the same wire format via `StreamTranslator`.

### Removed Capabilities

- Auto-registered chat routes, cookie/session conversation state, and Inertia page rendering. Hosts own all three.

## Impact

- **Code**: removal of the controller, request, routes file, and five HTTP-coupled `Services/ChatBot/` classes; refactor of `AiChatBotConversationService`'s generator and guards.
- **Tests**: delete `ChatBotPagePropsTest` (409), `ConversationSessionStoreTest` (311), `ChatBotCookieTest` (136). Rewrite `AiChatBotConversationServiceTest` (643) — all eleven tests assert on raw SSE strings and must move to structured events. Partially rewrite `PackageSmokeTest` and `FrontendAssetPublishingTest`.
- **README**: lines 235–266 and 267–360 and 400–451 are exclusively HTTP and go. Lines 361–399 (the SSE event tables) get re-framed as the turn-event vocabulary rather than deleted. A new service-layer section follows the existing "Getting an agent in code" precedent.
- **Dependencies**: with admin gone in 0.11 and pages gone here, **`inertiajs/inertia-laravel` should move from `require` to nothing at all.** A package that ships no UI should not force a UI framework on every host.
- **`reserved_slugs`**: keep, but note it becomes vestigial — it exists to stop root-mounted bots shadowing host routes, and after this change the host owns its own routing.
- **Host apps**: the most disruptive of the three changes. Any host using the package's chat routes must write its own controller. The published TypeScript client keeps working against a host-written endpoint that preserves the documented shape.
- **Version**: `0.13.0`. Explicitly not 1.0.

## Related

- **Raw exchange logging** (`Services/RawExchange/`, 855 lines with its own table, retention command, and interactive reader) is **retained** through all three releases — it has been the package's most useful debugging tool. It is flagged here as a candidate for extraction into its own package before any 1.0, since it is a `laravel/ai` debugging tool with no chatbot coupling. No action this release beyond recording the intent.

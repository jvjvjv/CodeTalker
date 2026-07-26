## Context

The package ships a complete chat backend and no description of what to render against it. Two surfaces are involved, and both are load-bearing:

| Surface | Size | Documented today |
| --- | --- | --- |
| Inertia props on `ai/ChatBot` | 12 props (13 via hash link) | Component name only, in a routes table |
| Inertia props on `ai/ChatBotsIndex` | 6 props, nested | Component name only |
| SSE event stream | 7 event types + `[DONE]` + `X-Chat-Hash` | Nothing |

The SSE stream is the expensive half. `CLAUDE.md` already calls it "a compatibility surface — do not change it", but it exists only as the output of `StreamTranslator` plus two `yield` statements in `AiChatBotConversationService` and one preamble in `ChatStreamResponse`. The only host app implementing it wrote 352 lines and still branches on `page_reload`, `tool_use_progress`, and a `thinking_delta` variant that this package has never emitted — dead code that exists precisely because there was no contract to check against.

The complete emitted set, gathered from the three producers:

- `status` — `{phase: 'request_received'|'model_loading', message: string}`
- `message_start` — `{message: {usage: {input_tokens: null}}}`
- `content_block_delta` — `{delta: {text: string}}`
- `reasoning_block_delta` — `{delta: {reasoning: string}}`
- `message_delta` — `{delta: {stop_reason: 'end_turn'|'max_tokens'|'tool_use'}, usage: {input_tokens, output_tokens}}`
- `message_stop` — `{}`
- `error` — `{message: string, reason?: 'max_stream_duration'|'provider_error'}`
- terminator — the literal `data: [DONE]\n\n`, sent on success and after a caught stream failure, but **not** after a `max_stream_duration` error

## Goals / Non-Goals

**Goals:**
- A host developer can build a working chat UI from the README alone, without reading package source.
- The two contracts become explicitly versioned, so `CLAUDE.md`'s "do not change the wire format" rule is checkable rather than tribal.
- The genuinely hard part — consuming the stream — is available as working, typed code a host can adopt or ignore.
- A host can name its components whatever it likes.

**Non-Goals:**
- **No React, Vue, or Svelte components.** Shipping UI would impose a framework, a styling system, and build tooling on a pure-PHP package, and any host with a design system would discard it — as the existing host effectively would.
- No change to the SSE format or any prop. Tidying the format (the `status` frames are arguably redundant with `message_start`) is a real breaking change and belongs in its own proposal.
- No runtime JavaScript dependency for host apps. Everything shipped is copied in by `vendor:publish` and owned by the host from that moment.
- No Node requirement for installing the package.

## Decisions

### 1. Publish types and client separately, and copy rather than depend

Two tags — `code-talker-types` and `code-talker-client` — rather than one, because they have different commitment levels: types are safe to re-publish on upgrade, while the client is expected to be edited once it lands in a host app.

Both are **copied**, not imported from `vendor/`. A host bundler pointed at `vendor/jvjvjv/code-talker/resources/js` would couple the host's build to a Composer path and break on any package layout change; and the client is small enough that forking it is the honest expectation. This mirrors how the package already treats route files, which are published and then owned.

*Alternative considered:* an npm package (`@jvjvjv/code-talker-client`) versioned alongside the Composer package. Rejected for now — two release channels to keep in lockstep for ~150 lines of code, and it would make the JS a dependency rather than a starting point. Worth revisiting if a second host app appears.

### 2. The client is callback-based and returns an abort handle

```ts
const turn = streamChatTurn(messageUrl, { message, name, email }, {
  onChatHash: (hash) => {},
  onStatus:   (phase, message) => {},
  onText:     (delta) => {},
  onReasoning:(delta) => {},
  onDone:     ({ stopReason, usage }) => {},
  onError:    ({ message, reason }) => {},
});

turn.abort();
await turn.done;
```

Callbacks rather than an async iterator: the consumers are UI frameworks that want to append deltas to state as they arrive, and callbacks work identically in React, Vue, and vanilla without an adapter. The abort handle matters because the backend specifically supports mid-turn cancellation — `ChatStreamResponse` sets `ignore_user_abort(true)` so a cancelled turn still persists its partial content — and a client that could not cancel would leave that behavior unreachable.

`onError` is a callback, not a rejection, because a streamed error arrives *after* partial text the UI should keep. Rejecting `done` would push consumers toward discarding it.

### 3. Component names move to config, not to a subclass hook

`code-talker.inertia.components.chat_bot` / `.chat_bots_index`, defaulting to `ai/ChatBot` / `ai/ChatBotsIndex`. Read in `ChatBotController` at render time.

This is the cheapest fix for a real host-app problem: today the only way to render a differently-named component is to override the controller action wholesale, which is exactly the pattern that made the host app fragile against the class decomposition. Config is a one-line override with no inheritance.

*Alternative considered:* resolving component names through the payload builders. Rejected — the payload builders own props, not routing to a view; putting the name there conflates two concerns.

**Two constraints make this backward-compatible, and both are easy to get wrong:**

`inertia` must be a **new top-level config key**. `mergeConfigFrom` is a shallow `array_merge($packageConfig, $hostConfig)`, so a host's already-published `config/code-talker.php` — which predates this key — simply doesn't override it and the package default survives. Nesting the setting under an existing block instead (say `conversations.inertia_component`) would mean the host's published `conversations` array replaces the package's wholesale and the new subkey vanishes.

Every read must carry an **inline default**. Laravel skips `mergeConfigFrom` entirely when the application's configuration is cached. A host that published its config before this key existed and then ran `config:cache` therefore has no `inertia` key at any level, and `config('code-talker.inertia.components.chat_bot')` returns `null` — a failure that appears only in production. `config(…, 'ai/ChatBot')` makes that unreachable, and is what every other nested read in `src/` already does.

### 4. TypeScript is typechecked in CI, and Node stays a dev-only concern

A `package.json` with a single `typescript` devDependency and a `typecheck` script (`tsc --noEmit`), plus a CI job. Shipping `.ts` that has never been compiled would be worse than shipping nothing.

Node does not become a host requirement: `package.json` is not consumed by Composer, host apps never install it, and the published files are plain `.ts`/`.d.ts` that a host's existing bundler compiles.

*Alternative considered:* skip the toolchain and rely on the host app to surface errors. Rejected — that makes the one consumer the test suite, which is how the dead event branches got there in the first place.

### 5. Document the contract as versioned API in the README

A **Frontend Integration** section carrying: both prop tables, the SSE event table, the terminator and header, the endpoints a UI calls, and an explicit statement that these follow the package's semver. Placed after "Chat Bots" and before "Tool Registration", since it is the natural next question after creating a bot.

This is what converts `CLAUDE.md`'s internal rule into a promise to host apps — and it is the part with the highest value-to-effort ratio in this change.

## Risks / Trade-offs

- **The published client rots against the host's edited copy.** → Accepted and made explicit: it is a starting point, documented as such, and the types (which *are* safe to re-publish) are what keep a host honest on upgrade.
- **Documenting a contract freezes it.** That is the point, but it does raise the cost of future format changes. → Mitigated by keeping this change strictly descriptive; the redundancy already present in the format is called out in the README as a known wart rather than silently blessed, so a future cleanup has a documented starting point.
- **PHP tests cannot cover the TypeScript.** → `tsc --noEmit` in CI covers compilation; behavior is covered by the existing PHP tests that pin the SSE bytes (`AiChatBotConversationServiceTest`) and the props (`ChatBotPagePropsTest`). The risk of the client diverging from the documented contract is real but bounded, and adopting it in the host app is the practical proving ground.
- **Config-driven component names are a new way to break a host** if someone sets them wrong. → Defaults reproduce today's behavior exactly, and a test asserts the defaults.

## Migration Plan

Ship in three commits, each independently useful: README documentation first (no code, immediate value), then the config-driven component names with their tests, then the published TypeScript with its CI job. No data or route migration. Host apps take no action unless they want the new tags.

## Open Questions

- Should the `status` frames be documented as deprecated? `request_received` and `model_loading` predate `message_start` and overlap with it. Documenting them as-is is correct for this change, but flagging them would signal intent. Leaning toward documenting them plainly and raising deprecation separately, so this change stays purely descriptive.
- Does the host app want the client to also cover the non-streaming endpoints (`status`, `warmup`, `reset`, `switch`)? They are ordinary JSON calls, so probably not worth wrapping — but if the host ends up writing the same fetch wrappers anyway, they belong here.

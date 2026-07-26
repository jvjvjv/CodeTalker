## Why

Installing this package gets you working chat endpoints and no way to know what to render against them. The controllers return `Inertia::render('ai/ChatBot', …)` with 13 props and stream an Anthropic-shaped SSE format with 7 event types, and **neither contract is documented anywhere** — the README names the two components in a routes table and stops. A host developer's only specification is the package source.

The cost is already visible in the one host app using this package: its `useChatStream.ts` is 352 lines, and it still branches on three event types the package has never emitted (`page_reload`, `tool_use_progress`, and a `thinking_delta` variant of `content_block_delta`) — defensive cruft accumulated because nobody could point at the real contract. Meanwhile `CLAUDE.md` already declares the SSE format a frozen compatibility surface, which is unenforceable when the surface is written down nowhere.

## What Changes

- Document both wire contracts in the README under a new **Frontend Integration** section: every Inertia prop for `ai/ChatBot` (including the by-hash-only `chatHash`) and `ai/ChatBotsIndex`, every SSE event type and its payload, the `[DONE]` sentinel, the `X-Chat-Hash` response header, and the endpoints a chat UI calls. State explicitly that both contracts follow the package's semver, so a prop rename or event-shape change is a breaking change.
- Ship publishable TypeScript declarations (`vendor:publish --tag=code-talker-types`) describing the Inertia prop shapes and the SSE event union, so a host's UI is typechecked against the package rather than against guesswork.
- Ship a publishable, dependency-free TypeScript stream client (`vendor:publish --tag=code-talker-client`) that turns the SSE response into typed callbacks (`onStatus`, `onText`, `onReasoning`, `onDone`, `onError`) and exposes the chat hash and an abort handle. Framework-agnostic — no React, Vue, or styling opinion — and **copied into the host, not depended upon**.
- Make the Inertia component names configurable under `code-talker.inertia.components`, defaulting to today's `ai/ChatBot` and `ai/ChatBotsIndex`, so a host can point the package at its own component paths without subclassing the controller.
- Add a `package.json` with a `typecheck` script (`tsc --noEmit`) and a CI job running it, so the shipped TypeScript is verified rather than assumed. Node is a dev-only concern; the package remains a pure-PHP dependency for host apps.
- **No React/Vue components are shipped.** Rendering, styling, and framework choice stay entirely with the host.
- **No behavior changes** to any existing endpoint, prop value, or SSE frame. The component-name config defaults reproduce current behavior exactly.

## Capabilities

### New Capabilities
- `frontend-integration-contract`: The documented, versioned contract between the package's HTTP surface and a host-app chat UI — the Inertia props of both pages, the SSE event stream and its terminator, the response headers, the publishable TypeScript types and stream client, and the configurability of the rendered component names.

### Modified Capabilities
- `php-class-decomposition`: `ChatBotPagePayload` and `ChatBotIndexPayload` gain a requirement that the Inertia component name is resolved from config rather than hard-coded, and the existing "Inertia props are unchanged" requirement is restated to bind the prop set to the newly published contract rather than to the implementation.

## Impact

- **Modified**: `README.md` (new Frontend Integration section), `config/code-talker.php` (new `inertia.components` block), `src/CodeTalkerServiceProvider.php` (two new publish tags), `src/Http/Controllers/ChatBotController.php` (component names from config).
- **New**: `resources/js/types/code-talker.d.ts`, `resources/js/client/code-talker-stream.ts`, `package.json`, `tsconfig.json`, a CI typecheck job, and PHP tests covering the config-driven component names and the publish-tag registration.
- **Host apps**: nothing breaks. The config defaults match today's component names, and both new publish tags are opt-in. A host that adopts the client and types can delete its hand-rolled SSE parser — for `jasonvertucio.com` that is `useChatStream.ts`, including its three dead event branches.
- **Not covered**: this change documents and types the contract; it does not alter the SSE format or any prop. Consolidating the format itself (e.g. dropping the redundant `status` frames) would be a separate, genuinely breaking change.
- **No** database, migration, route, or provider-communication changes.

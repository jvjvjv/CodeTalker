## Context

`ConversationTurnRunner` already emits a `tool_use_progress` frame on every
`ToolCallEvent` (and, when `$includeToolPayloads` is on, on every
`ToolResultEvent` too) — that part shipped in 0.12.0 and is documented in the
README's turn-events table. What's missing is everything downstream of PHP:

- `resources/js/types/code-talker.d.ts`'s `ChatStreamEvent` union has no
  `tool_use_progress` member, so a TypeScript host cannot narrow on it without
  an `as any` escape hatch.
- `resources/js/code-talker-stream.ts`'s `dispatch()` has no `case` for it
  either, so it falls into `default:` and is silently dropped — the client
  never calls anything for it, even though the server is already sending it.
- `page_reload` doesn't exist anywhere yet. `ToolResultConverter`'s docblock
  names `_page_reload` as a side-channel it "preserves" in a tool's structured
  result, but no tool sets that key today and nothing reads it — the turn
  runner never inspects a tool result for it, so there is no browser event
  and the whole thing is presently just a documented intention.

One more thing worth being precise about: `ToolResultEvent::toolResult->result`
is a **raw JSON string**, not an array. `BridgedTool::handle()` returns
`json_encode($this->registry->dispatch(...))` (a `string`, per laravel/ai's
`Tool` contract), and `InvokesTools::executeTool()` stores whatever the tool
returned verbatim. Detecting `_page_reload` means decoding that string, not
reading an array key off the event.

## Goals / Non-Goals

**Goals:**
- A tool can signal "server state changed, reload the page" by returning
  `_page_reload: true` in its structured result, and the browser sees a
  `page_reload` frame for it.
- `tool_use_progress` and `page_reload` are both real members of the
  published `ChatStreamEvent` union, matching what PHP actually emits
  (including the optional `input`/`output`/`successful` fields
  `$includeToolPayloads` adds).
- The published client dispatches both to new `onToolProgress` /
  `onPageReload` callbacks instead of dropping them.
- README's Frontend Integration section documents `page_reload` the same way
  it documents the other seven events.

**Non-Goals:**
- No latch/debounce logic (e.g. "only reload once per turn", "wait for the
  turn to finish before reloading"). The frame is emitted once per tool
  result that carries the flag; deciding whether/when to actually call
  `location.reload()` is a host UI concern, same as `tool_use_progress`
  today never decided how to render an activity indicator.
- No change to `dispatch()`'s general `default:` fallback for types this
  change doesn't know about — that stays a silent, forward-compatible no-op.
  This only adds explicit handling for these two now-real event types.
- `_page_reload`'s value is treated as a plain boolean signal. No tool
  currently sets it, so there's no existing convention (e.g. a target path)
  to preserve; anything other than a strict `true` is treated as absent.

## Decisions

**Detect `_page_reload` by decoding the tool result string in
`ConversationTurnRunner`, at the existing `ToolResultEvent` branch.**
That branch already exists (it records `$recordedToolResults` and, when
`$includeToolPayloads`, yields `tool_use_progress`). `json_decode()` the
string; if it's an array and `($decoded['_page_reload'] ?? false) === true`,
yield a `page_reload` frame right after. A decode failure or non-array result
is simply not a reload signal — no error, since most tool results are plain
text with no `_page_reload` key at all.
- *Alternative considered*: detect it in `ToolResultConverter` instead, since
  it already knows the `_page_reload` convention. Rejected — that class
  converts a single tool's `Response`/array/string into an array; it has no
  access to the turn's event stream to emit a browser frame from, and giving
  it one would mean threading a yield-capable collaborator through a static
  utility used for a completely different purpose (normalizing `handle()`
  return values for the agentic loop, not turn streaming).

**`page_reload` frame shape: `{type: 'page_reload'}`, no payload.**
Nothing in the proposal or the existing `_page_reload` convention implies a
target URL or reason string, and inventing one now would be speculative.
- *Alternative considered*: carry the tool name that triggered it (`tools:
  string[]`, mirroring `tool_use_progress`). Rejected for now — nothing
  downstream needs it yet, and it's an additive, non-breaking change to add
  later if a host has an actual use for it.

**`ChatStreamEvent`'s new members mirror the real PHP payload, optional
fields included.** `ToolUseProgressEvent` gets `text: string`, `tools:
string[]`, and optional `input?: unknown`, `output?: unknown`, `successful?:
boolean` (only present when the host turns on `$includeToolPayloads`).
Typing only the always-present fields would make the type lie about what a
host running with tool payloads enabled actually receives.

**Client dispatch mirrors every other event: an optional callback, called if
present, `dispatch()` returns `false` (turn continues).** Same shape as
`onStatus`/`onText`/etc. No special-casing.

## Risks / Trade-offs

- **A tool result that happens to already use an `_page_reload` key for
  something else** would now be interpreted as a reload signal. No tool in
  the package's own `Services/Mcp/Tools/ChatBot/` directory does this today
  (grepped, zero hits), and it's a leading-underscore key specifically
  documented as a side-channel — the collision risk is low and pre-existing
  in the convention, not introduced by this change.
- **`json_decode()` on every tool result adds a small amount of per-tool-call
  work.** Negligible next to an LLM round trip; not worth guarding behind a
  flag.
- **Widening `ChatStreamEvent` is technically a type-level breaking change**
  for a host with an exhaustive `switch` and no `default` case — TypeScript
  would no longer consider such a switch exhaustive. Documented as additive
  in the proposal because runtime behavior for existing hosts is unaffected
  (unknown members were already possible via `tool_use_progress` arriving
  un-typed); flagged here for completeness, not treated as a reason to gate
  it differently.

## Why

0.10.0 declared the frontend contract public API and published it as TypeScript declarations. Adopting that contract in a host app (`jasonvertucio.com`) surfaced two events the app has to emit for itself, both of which are generic enough that the package should own them:

- **`tool_use_progress`** — tells the browser the agent is calling a tool, so the UI can show an activity indicator. The host emits this on every `ToolCallEvent`. There is nothing app-specific in it, and the package already owns tool calling (MCP tools, `search-web`).
- **`page_reload`** — tells the browser a tool changed server state and the page should refresh. The package is already half-way here: `ToolResultConverter`'s docblock names `_page_reload` as a side-channel it deliberately preserves when returning structured tool payloads. The package understands the convention; it just never emits the browser event.

Because these are missing, a host that wants either one cannot use `ConversationTurnRunner` at all — it has to fork the whole turn loop. That is exactly what happened: `TargetedResumeService` in the host app hand-rolls a loop its own comment calls *"Mirrors the pre-0.6.0 loop"*, and has consequently missed every reliability fix since. See **Impact** for what that costs.

## What Changes

**Already shipped** (0.12.0, before this change was picked up): `ConversationTurnRunner` emits a `tool_use_progress` frame on each `ToolCallEvent`, and it's documented in the README's turn-events table. The scope below is what's left.

- The turn runner emits a `page_reload` frame when a tool result carries the `_page_reload` side-channel, giving the package a first-class way to drain what `ToolResultConverter` already preserves.
- `tool_use_progress` and `page_reload` both join the `ChatStreamEvent` union in `resources/js/types/code-talker.d.ts` — `tool_use_progress` is documented in the README but was never added to the published type declarations, so this closes that gap for both events at once.
- The published `streamChatTurn` client gains `onToolProgress` and `onPageReload` callbacks. **This also fixes a latent trap**: the client's `dispatch()` currently drops unrecognized types in its `default:` case, so any host emitting these events today would find them silently swallowed by the published client.
- Whether the reload latch belongs in the package's tool registry or stays a host concern is a design question, not settled here.

**Not** breaking: these are additive events on a stream consumers already ignore-by-default, and additive callbacks on the client. Hosts that do not use tools never see them.

## Capabilities

### New Capabilities
- `tool-activity-events`: the stream contract for reporting tool activity to the browser — progress while a tool runs, and a reload signal when a tool changes server state.

### Modified Capabilities

To be determined when this is picked up — check `openspec/specs/` for an existing chat-stream or frontend-contract capability that should take a delta instead of a new spec being created.

## Impact

- **Code**: `ConversationTurnRunner`, the published `code-talker.d.ts` and `code-talker-stream.ts`, and the README's Frontend Integration section.
- **Unblocks**: migrating a host off a forked turn loop onto `ConversationTurnRunner`. Measured against the host's fork, the package runner additionally provides:

  | Capability the fork lacks | Consequence |
  | --- | --- |
  | `TurnGuards::clientAborted()` | a cancelled turn keeps generating and billing tokens |
  | Per-step max-stream-duration guard | a runaway reasoning model can hang the request indefinitely |
  | Non-recoverable `ErrorEvent` → fail the turn | an LM Studio context overflow finishes as a silent success |
  | `RawExchangeContext` recording | `ai:read-exchange` cannot inspect those turns |
  | `TurnSequence::labelFor()` | the fork inlines `"{$base}.{$attempt}"` by hand |

  In short: a host that forked the loop to get these two events silently opted out of the 0.9.0 reliability work.
- **Version**: additive — a minor bump.
- **Origin**: the analysis behind this lives in the host app's archived `adopt-code-talker-frontend-contract` change (`design.md`, "Upstream candidates").

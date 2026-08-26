## Why

Host-configured credentials for the `http-request` tool currently live in one global config array (`code-talker.tools.http_request.credentials`), keyed by destination host and shared by every `AiSystem` in the application. A host running several agents — each meant to talk to a different external API — has no way to give agent A a credential for `api.a.com` without that same credential map being visible to (and technically reachable by) agent B, and no way to stop agent B from reaching `api.a.com` at all if it decides to. The only scoping today is "in `allowed_tools` or not," which is all-or-nothing for the whole tool, not per-destination. Running multiple narrowly-scoped agents — one per external domain — requires actual reachability restriction enforced server-side, not just per-agent credentials.

## What Changes

- Add per-`AiSystem` web-tool scoping: an allow-list of domains the system's `fetch-web-page` and `http-request` calls may reach, plus optional per-domain credentials attached to that system (not global).
- `HostGate`/`WebFetcher` enforce the allow-list server-side: a request to a domain outside the assigned list is refused before any network call is made, regardless of what the model declares in its `request_policy`. This applies even though `request_policy` itself remains a self-declared, non-enforced guardrail — domain scoping is the new enforced boundary layered on top of it.
- An `AiSystem` with no scoping configured is **unrestricted**, identical to current behavior — this is a new opt-in capability, not a default tightening. (Flagged as an explicit decision in Impact/design, since the alternative — secure-by-default with an explicit opt-out — was considered and rejected for backward compatibility; see design.md.)
- The existing global `code-talker.tools.http_request.credentials` config continues to work as a fallback for any `AiSystem` that has no per-system credential of its own for a given host — it is not removed. Per-system credentials, when present, take precedence over the global map for that host.
- `AiSystemManager::rules()` gains validation for the new field(s).
- **New field name required**: `AiSystem` already has a `credentials` column holding the *provider* API key/secret for the LLM endpoint itself. The new per-system web-tool credential map must not reuse that name — see design.md for the chosen field name.

## Capabilities

### New Capabilities
- `ai-system-web-scoping`: per-`AiSystem` domain allow-listing and credentials for the `fetch-web-page` and `http-request` tools, enforced server-side ahead of any network call.

### Modified Capabilities
(none — no existing spec currently documents `http-request`/`fetch-web-page` domain reachability; this proposal establishes it as a new capability rather than amending an undocumented one)

## Impact

- `database/migrations/` — new migration adding the scoping column(s) to `ai_systems`.
- `src/Models/AiSystem.php` — cast the new JSON column(s), following the existing `config`/`credentials`/`pricing_profile` array-cast pattern.
- `src/Services/Management/AiSystemManager.php` — `rules()` validation for the new field(s); `update()` already has a documented immutable-field dance for `provider`/`model` that must not be disturbed.
- `src/Services/Web/HostGate.php` — `refuse()` needs the allow-list to check against; currently takes no `AiSystem`-scoped input.
- `src/Services/Web/WebFetcher.php` — `credentialsFor()` needs to consult per-system credentials before falling back to global config; the constructor/call path needs the `AiSystem` (or an extracted scoping value object) available.
- `src/Support/ToolContext.php` — `forConversation()` currently derives `botName()` but not the `AiSystem`; the conversation's chat bot's system needs to become reachable here (`$conversation->aiChatBot?->aiSystem`), or scoping needs to reach `WebFetcher` some other way — decide in design.md.
- `src/Services/Mcp/Tools/ChatBot/FetchWebPageTool.php` and `HttpRequestTool.php` — `fetcher()` constructs `WebFetcher` directly from `HostGate`/`botName()`; both need the new scoping input threaded through.
- `tests/Feature/HttpRequestToolTest.php` and `FetchWebPageToolTest.php` — new tests for allow-list refusal and per-system credential precedence; all existing tests (unscoped `AiSystem`) must keep passing unchanged, proving the backward-compatible default.
- External MCP transport (`ToolContext::forUser()`) has no conversation and therefore no `AiSystem` — scoping in that path needs an explicit decision (unrestricted, or refuse by default) — see design.md.

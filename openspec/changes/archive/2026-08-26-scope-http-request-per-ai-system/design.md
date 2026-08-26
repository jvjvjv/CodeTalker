## Context

`AiSystem` already carries a `credentials` column (`encrypted:array`) — that field holds the *provider* API key for the LLM endpoint itself (`AiSystemManager::rules()` line 94, `casts()` line 47), consumed by `AiSystemProviderConfigurator`. It is unrelated to outbound web-tool requests and its name is taken.

Outbound web-tool credentials today are exactly one thing: `config('code-talker.tools.http_request.credentials')`, a plain (unencrypted) array of `host => header-map`, read by `WebFetcher::credentialsFor()` and merged into headers after `filterRequestHeaders()` strips anything model-supplied. There is no reachability restriction at all today — any `AiSystem` with `http-request`/`fetch-web-page` in `allowed_tools` can reach any public host `HostGate` doesn't refuse for being private/link-local.

`WebFetcher` is constructed fresh per tool call, directly inside `FetchWebPageTool::fetcher()` / `HttpRequestTool::fetcher()`, from `new HostGate(...)` and `$this->context->botName()`. `ToolContext` is built once per conversation (`ChatBotToolRegistry` → `ToolContext::forConversation($conversation)`) and exposes the conversation, but not the resolved `AiSystem` — `botName()` reaches `$conversation->aiChatBot?->name`; the same chain (`$conversation->aiChatBot?->aiSystem`) reaches the system.

## Goals / Non-Goals

**Goals:**
- Let a host restrict a given `AiSystem`'s `fetch-web-page`/`http-request` reach to an explicit domain allow-list, enforced before any network call.
- Let a host attach credentials to that `AiSystem` for its allowed domain(s), without those credentials being visible to any other `AiSystem`.
- Preserve current unrestricted behavior for every `AiSystem` that doesn't opt in.
- Keep the existing global `credentials` config working as a fallback.

**Non-Goals:**
- Changing the model-facing `request_policy` guardrail semantics (still self-declared, still not the enforcement point).
- Building a UI for managing the allow-list (this proposal is API/service-layer only, per the package's Management Services pattern — a host's admin screens consume `AiSystemManager`, they aren't shipped here).
- Scoping by anything other than domain (path/method-level scoping is out of scope).
- Changing the header-stripping invariant in `filterRequestHeaders()` — `Authorization`/`Cookie` etc. remain impossible for the model to set directly; this feature only changes *what the package itself may attach* and *which hosts are reachable at all*.

## Decisions

**New column: `web_tool_policy`, `encrypted:array`, on `ai_systems`.**
Structure:
```php
[
    'allowed_domains' => ['api.a.com', 'files.a.com'],   // null/absent = unrestricted (current behavior)
    'credentials' => [
        'api.a.com' => ['Authorization' => 'Bearer ...'],
    ],
]
```
One column bundling both concerns (mirrors how `config`/`credentials`/`pricing_profile` are each single JSON columns) rather than two separate columns, because the two are read together at the same call site and always belong to the same system. Encrypted like `credentials`, since it can hold secrets. Named to avoid any collision with the existing provider `credentials` column.

*Alternative considered*: reuse/extend the existing `config` JSON column with a `web_tools` key instead of a new column. Rejected — `config` is documented as general provider-tuning config (temperature, etc.) via `AiSystemProviderConfigurator`; overloading it with secrets would mean it can no longer be treated as non-sensitive, and it isn't encrypted today (`casts()` line 46 is plain `array`, not `encrypted:array`) — changing that cast to encrypt the whole column is a bigger, unrelated migration.

**Enforcement point: `HostGate::refuse()` gains an optional allow-list parameter.**
`HostGate` already owns the "may we go there" decision and the DNS-pinning that closes the TOCTOU gap; adding the domain check anywhere else (e.g. in the tool classes) would create a second place that can drift out of sync with the pinning logic. `refuse(string $url, string $method, RequestPolicy $policy, ?array $allowedDomains = null)` — when `$allowedDomains` is non-null and the requested host isn't in it (or a subdomain of an allowed entry — decide exact matching rule during implementation; exact-match only is the simpler starting point and is what's specified below), refuse before any DNS/network work.

**`WebFetcher` receives the `AiSystem`'s policy, not the whole `AiSystem`.**
`FetchWebPageTool::fetcher()`/`HttpRequestTool::fetcher()` extract `$this->context->conversation?->aiChatBot?->aiSystem?->web_tool_policy` into a small value object (or plain array) and pass it into `WebFetcher`'s constructor alongside the existing `HostGate`/bot-name/purpose args, rather than passing the `AiSystem` model itself — `WebFetcher` shouldn't need to know about the `AiSystem` model shape, only the two things it actually consumes (allow-list, credential map).

**Global config remains a fallback, per-system credentials take precedence.**
`WebFetcher::credentialsFor()` checks the system's own `web_tool_policy.credentials[$host]` first; if absent, falls through to `config('code-talker.tools.http_request.credentials')[$host]` exactly as today. This means an unscoped `AiSystem` behaves identically to today (global-only), and a scoped one can override the global credential for a host it's specifically configured for.

**Unrestricted-by-default, not secure-by-default, for backward compatibility.**
An `AiSystem` with `web_tool_policy` null (every existing row, post-migration) is unrestricted — identical to current behavior. This was chosen over "secure by default with explicit opt-out" because flipping every existing `AiSystem` to zero web reach on upgrade would silently break any host currently relying on unrestricted `fetch-web-page`/`http-request` with no migration path other than manually setting an allow-list on every row before or immediately after deploying. The trade-off — a new footgun-shaped default rather than tightened default security — is deliberate; it matches how `allowed_tools` itself works (opt-in gate, not opt-out).

**External MCP transport (`ToolContext::forUser()`): unrestricted, same as unscoped local chat.**
There is no `AiSystem` in that path at all today (`conversation` is `null`), and `HostGate` construction there doesn't currently vary by system. This proposal does not add `AiSystem` resolution to the MCP transport — scoping only applies where an `AiSystem` is already resolvable (the local chat loop). A future change could add per-caller scoping to the MCP path if needed; out of scope here.

## Risks / Trade-offs

- [Unrestricted default means a host must remember to configure the allow-list per agent] → Documented prominently in README/config comments, following the existing `http_request.credentials` comment-block pattern; `AiSystemManager` validation can warn (not block) when `http-request`/`fetch-web-page` is in `allowed_tools` with no `web_tool_policy` set, if the host wants that surfaced — flagged as optional, not required, in tasks.
- [Domain matching rule (exact host vs. subdomain-inclusive) is easy to get wrong in either direction] → Start with exact-match only (simplest, least surprising); a mismatch fails closed (refused), which is the safe direction to be wrong in.
- [`web_tool_policy` sits alongside two other JSON-ish columns (`config`, `credentials`) on the same model, growing the "is this JSON blob sensitive" surface a reader has to track] → Naming (`web_tool_policy`, not `credentials` or `config`) and the `encrypted:array` cast make the sensitivity explicit at the schema level.

## Migration Plan

1. Migration adds nullable `web_tool_policy` (text/JSON, encrypted) column to `ai_systems`; no backfill needed since null means unrestricted.
2. `AiSystem` model casts it `encrypted:array`.
3. `AiSystemManager::rules()` validates the structure when present.
4. `HostGate`, `WebFetcher`, `ToolContext`-adjacent call sites updated per Decisions above.
5. Rollback: drop the column; `HostGate`/`WebFetcher` calls simply stop receiving an allow-list argument (default `null` param keeps them working), reverting to fully unrestricted behavior — the same as any unscoped system today.

## Open Questions

- Exact-domain match only, or allow a leading-dot convention for subdomains (e.g. `.example.com` matching `api.example.com`)? Proposed: exact-match only for v1; revisit if a real use case needs subdomains.
- Should `AiSystemManager` surface a validation *warning* (not a hard failure) when a system has `http-request`/`fetch-web-page` allowed but no `web_tool_policy`? Leaning yes as a soft nudge, but not required for this change to ship.
- **Resolved during implementation**: the premise that `fetchPage()` never calls `credentialsFor()` was wrong. `fetchPage()` and `request()` both funnel through the shared `sendFollowingRedirects()`, which already calls `credentialsFor($currentUrl)` for every request regardless of caller — this is pre-existing behavior (`fetch-web-page` has always attached any globally-configured credential for a matching host, silently, with no test coverage either way). This change does not alter that: per-system credentials attach to `fetch-web-page` requests exactly as global ones already did. Not treated as a regression to fix here since it predates this change; flagged for awareness rather than acted on.

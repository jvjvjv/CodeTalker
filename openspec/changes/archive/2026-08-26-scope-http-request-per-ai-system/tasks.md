## 1. Schema

- [x] 1.1 Add migration: nullable `web_tool_policy` column (text) on `ai_systems`.
- [x] 1.2 Cast `web_tool_policy` as `encrypted:array` on `AiSystem`; add to `$fillable`.

## 2. Management layer

- [x] 2.1 Add `web_tool_policy` validation to `AiSystemManager::rules()` (nullable JSON/array; when present, `allowed_domains` is an array of strings, `credentials` is an array keyed by host to a string=>string header map).
- [x] 2.2 Decode `web_tool_policy` from JSON string or array before write, following the existing `config`/`credentials`/`pricing_profile` decode pattern (`AiSystemManager.php` ~line 326).

## 3. HostGate

- [x] 3.1 Add an optional `?array $allowedDomains` parameter to `HostGate::refuse()`.
- [x] 3.2 When non-null and non-empty, refuse any URL whose host is not an exact match in the list, before DNS resolution — return a caller-facing message naming the disallowed host.
- [x] 3.3 Confirm the allow-list check is re-applied on every redirect hop, not just the initial URL (`HostGate`/`WebFetcher`'s existing per-hop validation loop).

## 4. WebFetcher

- [x] 4.1 Accept the resolved web-tool policy (or a small value object built from it) in `WebFetcher`'s constructor, alongside the existing `HostGate`/bot-name/purpose args.
- [x] 4.2 Pass `allowed_domains` through to `HostGate::refuse()` on every call and every redirect hop.
- [x] 4.3 Update `credentialsFor()` to check the per-system credential map first, falling back to `config('code-talker.tools.http_request.credentials')`.
- [x] 4.4 Confirm `fetchPage()` (used by `fetch-web-page`) applies the allow-list but never calls `credentialsFor()` (per design.md's open question — resolve and document the answer inline).

## 5. ToolContext / tool wiring

- [x] 5.1 Expose the resolved `AiSystem` (or its `web_tool_policy`) via `ToolContext`, reachable from `$conversation?->aiChatBot?->aiSystem`.
- [x] 5.2 Update `FetchWebPageTool::fetcher()` and `HttpRequestTool::fetcher()` to pull the policy from `ToolContext` and pass it into `WebFetcher`.

## 6. Tests

- [x] 6.1 `HttpRequestToolTest`: request to an allowed domain succeeds for a scoped `AiSystem`.
- [x] 6.2 `HttpRequestToolTest`: request to a disallowed domain is refused before any HTTP call is made (assert no network call attempted, e.g. via `Http::fake()` never receiving it).
- [x] 6.3 `HttpRequestToolTest`: per-system credential is attached and never appears in tool output.
- [x] 6.4 `HttpRequestToolTest`: per-system credential does not leak to a second `AiSystem` without its own entry.
- [x] 6.5 `HttpRequestToolTest`: per-system credential takes precedence over a global-config credential for the same host.
- [x] 6.6 `HttpRequestToolTest`: redirect to a disallowed host is refused; redirect does not carry credentials to the new host.
- [x] 6.7 `FetchWebPageToolTest`: request to a disallowed domain is refused for a scoped `AiSystem`.
- [x] 6.8 Regression: every existing test in both files (unscoped `AiSystem`) continues to pass unmodified.
- [x] 6.9 `AiSystemManagerTest` (or equivalent): validation rejects malformed `web_tool_policy` shapes; accepts well-formed ones.

## 7. Documentation

- [x] 7.1 Document `web_tool_policy` in README.md's AI Systems section: shape, precedence over global config, unrestricted-by-default behavior.
- [ ] 7.2 Add a CHANGELOG entry once this ships (per repo convention — not during development).

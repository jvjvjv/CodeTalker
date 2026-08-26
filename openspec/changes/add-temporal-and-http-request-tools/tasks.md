## 1. Extract the shared web collaborator

- [x] 1.1 Create `src/Services/Web/FetchedResponse.php` — a `final class` with promoted `public readonly` properties (`url`, `status`, `contentType`, `body`, `headers`) and named static factory methods, matching the style of `Support\ToolContext`.
- [x] 1.2 Create `src/Services/Web/WebFetcher.php` and move into it, verbatim, the private logic now in `FetchWebPageTool`: URL scheme/host validation, the browser-like header set with `WebScraperUserAgent` identification, the 10s connect / 20s total timeouts, the 150,000-character body cap, `extractReadableText()`, `normalizeWhitespace()`, `truncateContent()`, `extractTargetHtml()`, and `<title>` extraction. Keep every error string byte-identical.
- [x] 1.3 Confirm `Services/Web/` is outside every directory `DiscoversAiToolHandlers` walks, and that neither new class extends `Laravel\Mcp\Server\Tool` or implements `AiToolHandlerContract`.
- [x] 1.4 Rewrite `FetchWebPageTool::handle()` as input mapping, one `WebFetcher` call, and response shaping. Do not change its `#[Name]`, `#[Description]`, `schema()`, constructor, response keys, or error strings.
- [x] 1.5 Run `vendor/bin/phpunit tests/Feature/FetchWebPageToolTest.php` **without editing that file**. A failure means the extraction drifted — fix `WebFetcher`, not the test.
- [x] 1.6 Run `vendor/bin/phpunit tests/Feature/ChatBotToolRegistryTest.php` to confirm the discovered tool set is still exactly the three existing tools.

## 2. Temporal information tool

- [x] 2.1 Add `src/Services/Mcp/Tools/ChatBot/GetTemporalInformationTool.php` with `#[Name('get-temporal-information')]`, a `#[Description]` that tells the model to call it before answering anything date-relative, and a single optional `timezone` string input.
- [x] 2.2 Implement zone resolution: IANA identifier, or a UTC offset in `±HH:MM` / `±HHMM` / `±H` form normalized to `±HH:MM`; fall back to `config('app.timezone')` when no input is given.
- [x] 2.3 Return an error naming the accepted forms when the input resolves to neither. Do not fall back to a default zone on an unparseable value.
- [x] 2.4 Build the successful `Response::structured()` payload on `CarbonImmutable`: `iso8601`, `utc_iso8601`, `timezone`, `utc_offset`, `unix_timestamp`, `date`, `time`, `day_of_week`, `human`.
- [x] 2.5 Add `tests/Feature/GetTemporalInformationToolTest.php` driving every scenario in `specs/temporal-information-tool/spec.md`, using `Carbon::setTestNow()` for determinism — including the two-zone test asserting `unix_timestamp` and `utc_iso8601` are identical while the local fields differ.

## 3. HTTP request tool — inputs and the policy gate

- [x] 3.1 Add `src/Services/Mcp/Tools/ChatBot/HttpRequestTool.php` with `#[Name('http-request')]` and a `#[Description]` that states the `request_policy` requirement, so the model learns it from the schema rather than from a failed call.
- [x] 3.2 Define the schema: `url` (required), `method` (required, enum `GET`/`POST`/`PUT`/`PATCH`/`DELETE`), `request_policy` (required object: `allowed_methods` array, `allow_private_hosts` boolean, `allowed_hosts` array), `body`, `headers`, `keep_html`, `target_selector`, `truncate_content`.
- [x] 3.3 Implement the gate, in order and before any network call: missing/malformed `request_policy` or empty `allowed_methods` → error naming the field; method not in `allowed_methods` → error quoting the declared list; `allowed_hosts` present and host not in it → error; host resolving to loopback/link-local/RFC1918 with `allow_private_hosts` not `true` → error naming the host.
- [x] 3.4 Refuse any scheme but `http`/`https` unconditionally, before the policy gate — it is not policy-negotiable.
- [x] 3.5 Add `tests/Feature/HttpRequestToolTest.php` covering the gate scenarios, each asserting via `Http::fake()` that **no request was sent** when the gate refuses.

## 4. HTTP request tool — headers and credentials

- [x] 4.1 Implement case-insensitive header filtering in `WebFetcher`: strip `authorization`, `proxy-authorization`, `cookie`, `host`, `connection`, `keep-alive`, `transfer-encoding`, `te`, `trailer`, `upgrade`, `proxy-connection`.
- [x] 4.2 Make package-owned headers (`User-Agent`, `Accept-Encoding`) win over a caller-supplied value of the same name.
- [x] 4.3 Report the stripped header names in the tool's response so a caller learns why its auth attempt did nothing.
- [x] 4.4 Add a `tools.http_request.credentials` block to `config/code-talker.php` — a host-keyed map of header sets — with a documented example and inline defaults on every read (`config('code-talker.tools.http_request.credentials', [])`), because `mergeConfigFrom` is skipped when the host has cached config.
- [x] 4.5 Apply configured credentials after filtering, matched on exact host, case-insensitively. Assert in a test that the credential value appears in the outbound request and in no part of the returned response.

## 5. HTTP request tool — response decoding

- [x] 5.1 Dispatch on `Content-Type` in `WebFetcher`: JSON (`application/json`, `+json`), XML (`application/xml`, `text/xml`, `+xml`), HTML/XHTML, other `text/*`, everything else.
- [x] 5.2 Decode JSON with `json_decode(..., true)`; on failure return the raw text plus a decode-failure note rather than an error.
- [x] 5.3 Decode XML with `simplexml_load_string` under `libxml_use_internal_errors(true)` and `LIBXML_NONET`, never passing `LIBXML_NOENT`. Add a test asserting an external-entity document does not resolve the entity.
- [x] 5.4 Route HTML and XHTML through the existing readable-text path, honoring `keep_html` and `target_selector`.
- [x] 5.5 Refuse any other content type with an error naming it. Do not base64-encode a binary body.
- [x] 5.6 Truncate structured content after decoding and flag it, so a truncated payload is never returned as malformed structure.
- [x] 5.7 Extend `tests/Feature/HttpRequestToolTest.php` with the decoding, size-cap, connection-failure, and non-2xx scenarios from `specs/http-request-tool/spec.md`.

## 5b. Redirect handling (added during implementation)

- [x] 5b.1 Disable Guzzle's automatic redirect following for `http-request` (`allow_redirects => false`), leaving `fetch-web-page` on the default so its pinned behavior is unchanged.
- [x] 5b.2 Re-run the scheme check and the full declared-policy gate against every redirect destination before issuing it, capped at 5 hops, downgrading method and dropping the body on 301/302/303.
- [x] 5b.3 Re-derive host-configured credentials per hop so a redirect cannot carry the previous host's token.
- [x] 5b.4 Add regression tests for redirect-into-private-network, redirect-off-allow-list, permitted redirect, credential non-carry, and redirect loop; verify they fail when the hop gate is disabled.

## 6. Registration and wiring

- [x] 6.1 Add `GetTemporalInformationTool` and `HttpRequestTool` to `Mcp/Servers/CodeTalkerServer::$tools`. Add no `shouldRegister()` gate — neither reads user-scoped data.
- [x] 6.2 Update `tests/Feature/ChatBotToolRegistryTest.php` for the expanded discovered tool set, and `tests/Feature/ManagementServicesTest.php` where it asserts the full tool list.
- [x] 6.3 Confirm both tools work with `ToolContext::forUser()` (conversation `null`) — `botName()` returns `null` and `WebScraperUserAgent::forBotName(null)` falls back to `'ChatBot'`.
- [x] 6.4 Confirm neither tool needs an `allowed_tools` migration: `fetch-web-page` is unrenamed and the two new names are additive.

## 7. Documentation

- [x] 7.1 Update the README's built-in tool list (currently three entries at `README.md:453`) with both new tools, documenting `http-request`'s `request_policy` input and the `tools.http_request.credentials` config block.
- [x] 7.2 Add a security note to the README, next to `http-request`, stating plainly that a caller-declared policy is not a defence against a prompt-injected model, and that the tool should stay out of `allowed_tools` for any bot taking untrusted input.
- [x] 7.3 Add a `CHANGELOG.md` entry under a new minor version — New Features for both tools; no Breaking Changes — and note that a host which already registered its own `http-request` through `addToolDirectory()` should check for the name collision before upgrading.

## 8. Verification

- [x] 8.1 Run the full suite with `composer test` and confirm it passes.
- [x] 8.2 Confirm `tests/Feature/FetchWebPageToolTest.php` is unmodified in the final diff — `git diff --stat` must not list it.
- [x] 8.3 Re-read `specs/http-request-tool/spec.md` and `specs/temporal-information-tool/spec.md` and confirm every scenario has a corresponding test.

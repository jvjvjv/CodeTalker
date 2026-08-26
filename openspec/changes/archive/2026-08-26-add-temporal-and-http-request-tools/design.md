## Context

`FetchWebPageTool` is the package's only outbound-HTTP tool. Everything it does lives in one class: URL validation, the browser-like header set, `WebScraperUserAgent` identification, connect/read timeouts, a 150 KB body cap, content-type gating, `<title>` extraction, `target_selector` scoping, readable-text extraction, whitespace normalization, and 20 KB content truncation. All of it is `private`, so a second tool cannot reuse any of it.

The constraints this design has to hold:

- **`fetch-web-page` is a live contract.** `FetchWebPageToolTest` and `ChatBotToolRegistryTest` pin its name, inputs, and response shape; `AiSystem::allowed_tools` rows in host databases name it; a migration already exists purely to rename it once. The extraction must be behavior-preserving.
- **Tool directories are walked recursively.** `DiscoversAiToolHandlers` uses `File::allFiles()` over `Services/Mcp/Tools/ChatBot/`, and anything under it that extends `Tool` or implements `AiToolHandlerContract` self-registers as a tool. `ChatBotToolRegistryTest` pins the discovered set, so a stray class fails the suite — but only after it has already shipped a phantom tool to the model.
- **Tools run inside the turn.** A chat turn is under a max-stream-duration guard (`TurnGuards`). A tool that blocks for 30s eats that budget for the whole turn, not just its own step.
- **Two transports.** `ToolContext::conversation` is `null` on the external MCP transport. `botName()` already degrades to `null` there, and `WebScraperUserAgent::forBotName(null)` already falls back to `'ChatBot'`, so neither tool needs a conversation.

## Goals / Non-Goals

**Goals:**

- Give the agent an accurate clock, answerable in a caller-supplied timezone or UTC offset.
- Give the agent a general HTTP capability covering `GET`/`POST`/`PUT`/`PATCH`/`DELETE` and JSON/XML/text/HTML responses.
- Make the model state its intended request policy explicitly, and refuse the request when it has not.
- Keep credentials out of the model's reach entirely.
- Collapse the fetch-and-extract logic into one shared collaborator, leaving `fetch-web-page` byte-identical in behavior.

**Non-Goals:**

- A general-purpose scraper. No JavaScript execution, no redirect-chain introspection, no cookie jar, no session state across calls.
- Binary payloads. No file downloads, no base64 bodies, no streaming.
- A host-side policy ceiling that overrides what the model declares. Considered and deferred — see **Open Questions**.
- Changing anything about `search-web`, which keeps its own `SearchHttpClients`.
- Any change to the turn event vocabulary or SSE wire format.

## Decisions

### 1. The shared collaborator lives outside the walked tool directories

`Jvjvjv\CodeTalker\Services\Web\` — a sibling of `Services/RawExchange/` and `Services/Conversation/`.

*Alternative considered:* colocate under `Services/Mcp/Tools/ChatBot/Web/`, matching the `SearchWeb/` precedent. Rejected on two grounds. `SearchWeb/` holds the internals of exactly one tool, which is what the existing `php-class-decomposition` requirement describes — this collaborator is shared by two tools and has no single origin class. And placing shared infrastructure inside a recursively-walked tool directory means every class in it is one accidental `extends Tool` away from becoming a phantom tool. Moving it out removes that hazard by construction rather than by vigilance. This is why the proposal takes a delta on `php-class-decomposition`.

### 2. One shared behavior class, plus a value object

`Services/Web/WebFetcher` is the single class both tools call — it performs the request and decodes the response. It carries the request execution, the header policy, the content-type dispatch, HTML title/selector/readable-text extraction, whitespace normalization, and truncation.

`Services/Web/FetchedResponse` is a separate `final class` with promoted `public readonly` properties, because the existing decomposition spec's value-object rule says data carriers get their own file in that style (matching `Support\ToolContext` and `Services\RawExchange\RawExchangeFrame`). Splitting further — a separate extractor, decoder, and truncator — was considered and rejected: it fragments logic that is only meaningful together and works against the "single class that serves both tools" intent.

Both tools stay thin. `FetchWebPageTool::handle()` becomes input mapping, one `WebFetcher` call, and response shaping; its existing error strings move with it verbatim so the pinned messages do not drift.

### 3. The request policy is a required tool input, and absence is a hard failure

`http-request` takes a `request_policy` object:

```
request_policy: {
  allowed_methods: ["GET", "POST"],      // required, non-empty
  allow_private_hosts: false,            // default false
  allowed_hosts: ["api.example.com"]     // optional; when present, the host must match
}
```

The gate runs before the socket opens, in this order:

1. `request_policy` missing, not an object, or `allowed_methods` empty → refuse. The error names the exact field the model must supply, so the model can retry within the same turn rather than treating it as a dead end.
2. `method` not in `allowed_methods` → refuse, quoting the policy the model itself declared.
3. `allowed_hosts` present and the URL host is not in it → refuse.
4. Host resolves to a loopback, link-local, or RFC1918 address and `allow_private_hosts` is not `true` → refuse.

Scheme validation is **not** policy-gated. Anything but `http`/`https` is refused unconditionally — that is a correctness bound, not a caller preference, and no legitimate policy declaration wants `file://`.

*Alternative considered:* a static config allow/deny list, with no policy input. Rejected because it makes the tool useless out of the box for the common case (a bot that should reach one API the host has not pre-registered), and it puts the decision far from the transcript. The declared-policy form has one property the config form does not: the model's intent is written into the `AiLlmMessage` request log, so an audit shows what the model *believed* it was allowed to do, next to what it did.

*Alternative considered:* default-deny private hosts with no policy input at all. Rejected — it is the same fail-open-for-public-hosts posture as `fetch-web-page`, and the user asked for an explicit declaration.

### 4. Header filtering, and credentials the model never sees

Model-supplied headers pass through a deny-list before the request: `authorization`, `proxy-authorization`, `cookie`, `host`, and the hop-by-hop set (`connection`, `keep-alive`, `transfer-encoding`, `te`, `trailer`, `upgrade`, `proxy-connection`). Matching is case-insensitive. A stripped header is reported back in the tool response so the model learns why its auth attempt did nothing, rather than silently retrying.

Package-owned headers (`User-Agent` from `WebScraperUserAgent`, `Accept-Encoding`) always win over a model-supplied value.

Credentials come from config, keyed by exact host:

```php
'tools' => [
    'http_request' => [
        'credentials' => [
            'api.example.com' => ['Authorization' => 'Bearer ' . env('EXAMPLE_API_TOKEN')],
        ],
    ],
],
```

Applied after filtering, so a config credential can set a header the model is forbidden to set. The token value never appears in the tool's inputs or its response — only in the `ai_provider_exchanges` raw capture, which is already a secrets-bearing table.

### 5. Response decoding is content-type dispatched, and binary is refused

| Content type | Returned as |
| --- | --- |
| `application/json`, `*/*+json` | `json_decode(..., true)` structure under `content`; on decode failure, raw text plus a `decode_error` note |
| `application/xml`, `text/xml`, `*/*+xml` | array via `simplexml_load_string`, external entity loading left disabled and `LIBXML_NONET` set |
| `text/html`, `application/xhtml+xml` | readable text, or raw HTML with `keep_html`; honors `target_selector` |
| `text/plain` and other `text/*` | whitespace-normalized text |
| anything else | refused, with the content type named in the error |

Refusing binary rather than base64-encoding it is deliberate: a 20 KB base64 blob in a tool result is tokens spent on something no model can act on.

The existing caps carry over unchanged — 150 KB read off the wire, 20 KB of returned content unless `truncate_content` is `false`. JSON and XML are truncated *after* decoding by re-encoding and cutting, so a truncated payload is flagged rather than silently returned as malformed structure.

### 6. Timeouts stay at `fetch-web-page`'s values

10s connect, 20s total, non-configurable in this change. Matching the existing tool keeps the turn-budget behavior the max-stream-duration guard already tolerates. Making it a model input invites a 120s declaration that hangs a turn.

### 7. Temporal tool resolves zone, offset, or neither

`get-temporal-information` takes one optional `timezone` input accepting either an IANA identifier (`America/New_York`) or a fixed UTC offset (`-05:00`, `+0530`, `+5`). Offsets are normalized to `±HH:MM` before constructing the zone. An unparseable value is an **error**, not a silent fallback — a wrong-timezone answer the model trusts is worse than a refusal. With no input, the zone is `config('app.timezone')`.

The response carries both the instant and the pre-computed parts — `iso8601`, `utc_iso8601`, `timezone`, `utc_offset`, `unix_timestamp`, `date`, `time`, `day_of_week`, `human` — so the model does no calendar arithmetic on a string. Built on `CarbonImmutable`, which makes `Carbon::setTestNow()` the test seam and keeps the tool deterministic under test.

### 8. Both tools register on the external MCP server, `http-request` in full

Added to `CodeTalkerServer::$tools`. No `shouldRegister()` gate on either: neither reads user-scoped data, so neither needs `hasIdentity()`. `http-request`'s write methods are exposed there too — that transport is `auth:sanctum` by default and the server is off unless `code-talker.mcp.enabled` is true, so the caller is an authenticated principal the host chose to admit.

## Risks / Trade-offs

- **A self-declared policy is not a security boundary** → A prompt-injected or adversarial model declares `allow_private_hosts: true` and reaches the host's internal services. The gate stops *accidental* reach and makes intent auditable; it does not stop intent. Mitigations, in order of what a host should actually do: keep `http-request` out of `AiSystem::allowed_tools` for any bot taking untrusted input; run the host app where its internal services are not reachable from the PHP process; and, if that is not possible, adopt the deferred config ceiling in **Open Questions**. This is documented in the README next to the tool, not buried.

- **DNS rebinding defeats the private-host check** → The host resolves public at check time and private at connect time. Mitigation deferred: the check uses the resolved address at validation time only. Closing it properly means pinning the resolved IP into the connection, which Guzzle supports but Laravel's `Http` facade does not expose cleanly. Recorded as a known limitation rather than half-solved.

- **XXE via the XML branch** → Malicious XML pulls local files or internal URLs through entity expansion. Mitigation: entity substitution is never enabled (no `LIBXML_NOENT`), `LIBXML_NONET` is set, and libxml errors are captured rather than emitted. PHP 8.3's default already disables external entity loading; the flags make the intent explicit rather than relying on it.

- **The extraction silently changes `fetch-web-page` behavior** → Mitigation: `FetchWebPageToolTest` must pass **unmodified** through the extraction. Any change to that file during this work is a signal the extraction drifted, not that the test needed updating. The spec delta adds a scenario pinning the MCP contract, matching the one that already exists for `SearchWebTool`.

- **Two more tools inflate every request's tool schema** → Every `AiSystem` that allows them pays their schema in input tokens on every turn, and `http-request`'s policy object is not a small schema. Mitigation: `allowed_tools` is already opt-in per system, so the cost lands only where the capability was asked for.

- **Tool-name collision with a host-registered tool** → A host may already have registered its own `http-request` through `addToolDirectory()`. Package tools are discovered first and host directories second, so the host's would overwrite the package's. Mitigation: call it out in the CHANGELOG as a name to check before upgrading.

## Migration Plan

Additive; no migration, no `allowed_tools` remap. `fetch-web-page` keeps its name and contract, so existing rows keep working untouched. Hosts opt into the new tools by adding `get-temporal-information` and `http-request` to an `AiSystem`'s `allowed_tools`. Rollback is removing the two tool classes — the shared `WebFetcher` extraction can stay, since `fetch-web-page` on top of it is behavior-identical either way.

## Open Questions

- **Host-side policy ceiling.** A `code-talker.tools.http_request.max_policy` block capping what a declared policy may grant (e.g. `allow_private_hosts` can never be honored regardless of what the model declares) would turn the gate into a real boundary. Deliberately out of scope here — it changes the tool from "model declares, tool obeys" to "model declares, host adjudicates," which is a different capability and deserves its own change. Revisit if `http-request` is ever enabled on a bot exposed to untrusted input.
- **Per-host credentials matched by exact host only.** Wildcard or suffix matching (`*.example.com`) is unimplemented. Add it if a real host configuration needs it; guessing the matching semantics now risks getting the precedence rules wrong.
- **Whether `get-temporal-information` should read the visitor's zone from the conversation.** `ToolContext` carries no timezone today, and adding one touches conversation persistence. The explicit `timezone` input covers the case; revisit if the model consistently guesses wrong because it has no idea where the visitor is.

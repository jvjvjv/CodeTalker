## Context

`WebFetcher::MAX_BODY_LENGTH` (150000 bytes) and `MAX_CONTENT_LENGTH` (20000 chars) are `public const`, referenced from within `WebFetcher` itself (`fetchPage()`/`request()`, `structuredResponse()`, `textResponse()`, `htmlResponse()`, `truncateContent()`) and from the two tool classes' schema descriptions (`FetchWebPageTool.php:47`, `HttpRequestTool.php:81`, both interpolating `WebFetcher::MAX_CONTENT_LENGTH` into the `truncate_content` argument's description text shown to the model).

## Goals / Non-Goals

**Goals:**
- Make both limits host-configurable via `.env`, defaulting to today's values.
- Keep the change confined to `WebFetcher` and the two tool schema descriptions — no new DB columns, no per-`AiSystem` variance.

**Non-Goals:**
- Per-`AiSystem`/`AiChatBot` override (explicitly deferred; global only, per user decision).
- Changing truncation *behavior* (the `truncate_content` opt-out semantics, the body-length cap being non-skippable) — only the numeric ceilings become configurable, not which paths respect them.

## Decisions

**Config location: `code-talker.tools.web_fetcher`, not `tools.http_request`.**
Both limits apply to `fetch-web-page` as well as `http-request` (they live in shared `WebFetcher` code, not in the `http_request`-only header-filtering path), so nesting them under `http_request` would misname their scope. A new `web_fetcher` sibling key keeps the file's existing `tools.<tool>` shape consistent while being accurate about which tools are affected.

**Constants removed, not kept as fallback defaults.**
Config already carries the default via its own array literal (`env('CODE_TALKER_MAX_BODY_LENGTH', 150000)`), so a second default baked into a class constant would be a second source of truth that could drift. `WebFetcher` reads `config('code-talker.tools.web_fetcher.max_body_length')` / `...max_content_length` directly at each use site.

**Tool schema description text becomes dynamic.**
`FetchWebPageTool.php:47` and `HttpRequestTool.php:81` currently interpolate the constant into a string shown to the model (`"...truncated at {N} bytes."`). Read the same config value there instead, so the description stays accurate for a host that raises or lowers the limit — a static string here would silently lie to the model about the actual behavior once config diverges from the old default.

**No caching/validation layer beyond normal Laravel config casting.**
Both values are read via `(int) config(...)`, matching the existing pattern for `CODE_TALKER_CONVERSATION_IDLE_MINUTES` and `CODE_TALKER_MAX_STREAM_SECONDS` in the same config file. No explicit lower/upper bound validation is added — a host that sets a nonsensical value (e.g. 0 or negative) gets whatever `mb_substr`/wire-read does with it; this matches the existing lack of validation on other numeric config in this file, and is called out as an open question below rather than pre-emptively guarded.

## Risks / Trade-offs

- [A host sets `max_content_length` far above the model's own context budget] → Not this package's concern to prevent; the model's context window and provider limits already bound practical usefulness. No mitigation added here.
- [Existing hardcoded-expectation tests break if they assert `WebFetcher::MAX_CONTENT_LENGTH` directly] → Confirmed by repo search: no test references either constant by name; two `HttpRequestToolTest` assertions hardcode the literal `20000`, which still holds under the new default and needs no change, but should gain a companion test that overrides config to a different value and asserts truncation follows it.

## Migration Plan

No data migration. Deploying is: publish/merge the new config keys (existing hosts that never re-publish `code-talker.php` get the defaults via `config('code-talker.tools.web_fetcher.max_body_length', 150000)`-style inline defaults, per the project's documented pattern for config added after a host's initial publish — see CLAUDE.md's note on `config()` inline defaults and cached config). Rollback is reverting the code change; no state to unwind.

## Open Questions

- Should zero/negative values be explicitly rejected (e.g. via config validation at boot) rather than left to produce whatever `mb_substr`/curl body-size behavior results? Leaning no — consistent with how other numeric config in this file is handled — but flagging for review.

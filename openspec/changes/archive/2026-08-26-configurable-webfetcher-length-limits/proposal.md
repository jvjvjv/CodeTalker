## Why

`WebFetcher::MAX_BODY_LENGTH` (150000 bytes, raw wire read) and `WebFetcher::MAX_CONTENT_LENGTH` (20000 characters, processed output) are hardcoded class constants. A host that wants `fetch-web-page` / `http-request` to return more (or less) content per call — e.g. a bot that summarizes long documents, or a stricter deployment that wants a tighter cap to control token spend — currently has to fork the package or subclass `WebFetcher` to change either value. These are operational tuning knobs, not security boundaries, so they belong in config.

## What Changes

- Add two new config keys under `code-talker.tools.web_fetcher`: `max_body_length` and `max_content_length`, backed by `CODE_TALKER_MAX_BODY_LENGTH` and `CODE_TALKER_MAX_CONTENT_LENGTH` env vars.
- Defaults preserve current behavior exactly: `150000` and `20000`.
- `WebFetcher` reads both values from config at the point of use instead of referencing its own class constants.
- The class constants `MAX_BODY_LENGTH` and `MAX_CONTENT_LENGTH` are removed; nothing outside `WebFetcher` currently references them (confirmed by repo-wide search), so this is not a breaking change to any documented public API.
- No per-`AiSystem` or per-`AiChatBot` override — this is a single global setting, matching how `code-talker.raw_exchanges` and `code-talker.conversations.max_stream_seconds` are already configured.

## Capabilities

### New Capabilities
- `web-fetcher-length-limits`: configurable global caps on raw response body size and processed content size returned by the `fetch-web-page` and `http-request` tools.

### Modified Capabilities
(none — no existing spec currently documents these limits)

## Impact

- `src/Services/Web/WebFetcher.php` — remove the two constants, read config instead, at every call site listed in the design doc.
- `config/code-talker.php` — new `tools.web_fetcher` section, following the existing documented-inline-default pattern used elsewhere in this file.
- `tests/Feature/HttpRequestToolTest.php` — two assertions currently hardcode `20000` as the expected truncated length; these must keep passing against the new default and should also be exercised against a non-default config value to prove the setting takes effect.
- `tests/Feature/FetchWebPageToolTest.php` — no current assertions on these limits; consider adding one for the config path if `fetchPage()` also flows through `MAX_CONTENT_LENGTH`/`MAX_BODY_LENGTH`.
- No `AiSystem`/`AiChatBot` schema changes.

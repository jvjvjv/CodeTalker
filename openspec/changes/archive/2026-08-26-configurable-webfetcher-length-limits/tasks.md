## 1. Config

- [x] 1.1 Add `tools.web_fetcher.max_body_length` and `tools.web_fetcher.max_content_length` to `config/code-talker.php`, backed by `CODE_TALKER_MAX_BODY_LENGTH` (default 150000) and `CODE_TALKER_MAX_CONTENT_LENGTH` (default 20000) env vars, with a doc comment matching the file's existing style.

## 2. WebFetcher

- [x] 2.1 Remove `WebFetcher::MAX_BODY_LENGTH` and `WebFetcher::MAX_CONTENT_LENGTH` constants.
- [x] 2.2 Replace every internal reference to those constants with reads from the new config keys (fetch/truncate call sites in `fetchPage()`/`request()`, `structuredResponse()`, `textResponse()`, `htmlResponse()`, `truncateContent()`).

## 3. Tool schema descriptions

- [x] 3.1 Update `FetchWebPageTool.php`'s `truncate_content` schema description to read the configured content length instead of the removed constant.
- [x] 3.2 Update `HttpRequestTool.php`'s `truncate_content` schema description the same way.

## 4. Tests

- [x] 4.1 Add a test overriding `code-talker.tools.web_fetcher.max_body_length` in config and asserting the raw body is truncated at the new value.
- [x] 4.2 Add a test overriding `code-talker.tools.web_fetcher.max_content_length` and asserting processed content truncates at the new value (both for `fetch-web-page` and `http-request`).
- [x] 4.3 Confirm the existing hardcoded-`20000` assertions in `HttpRequestToolTest.php` still pass unmodified against the default config.
- [x] 4.4 Add a test asserting `truncate_content: false` is unaffected by the configured cap.

## 5. Documentation

- [x] 5.1 Document the two new env vars in README.md's Configuration section, alongside the other `providers.*`/`conversations.*` env vars.

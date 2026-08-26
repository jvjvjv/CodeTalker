## ADDED Requirements

### Requirement: Configurable raw body length cap
The system SHALL read the maximum number of bytes read off the wire before a response body is truncated from `config('code-talker.tools.web_fetcher.max_body_length')`, defaulting to 150000 when unset, instead of a hardcoded constant.

#### Scenario: Default body length cap applies when unconfigured
- **WHEN** `CODE_TALKER_MAX_BODY_LENGTH` is not set in the host's environment
- **THEN** `fetch-web-page` and `http-request` truncate the raw response body at 150000 bytes, matching current behavior

#### Scenario: Host-configured body length cap applies
- **WHEN** the host sets `CODE_TALKER_MAX_BODY_LENGTH` to a different value
- **THEN** `fetch-web-page` and `http-request` truncate the raw response body at that configured value instead of 150000

### Requirement: Configurable processed content length cap
The system SHALL read the maximum number of characters of decoded/processed content returned (unless truncation is declined) from `config('code-talker.tools.web_fetcher.max_content_length')`, defaulting to 20000 when unset, instead of a hardcoded constant.

#### Scenario: Default content length cap applies when unconfigured
- **WHEN** `CODE_TALKER_MAX_CONTENT_LENGTH` is not set in the host's environment
- **THEN** processed content returned by `fetch-web-page` and `http-request` is truncated at 20000 characters when `truncate_content` is not declined, matching current behavior

#### Scenario: Host-configured content length cap applies
- **WHEN** the host sets `CODE_TALKER_MAX_CONTENT_LENGTH` to a different value
- **THEN** processed content is truncated at that configured value instead of 20000

#### Scenario: Truncation opt-out is unaffected
- **WHEN** a tool call declares `truncate_content: false`
- **THEN** the configured content length cap is not applied, regardless of its value

### Requirement: Tool schema descriptions reflect the configured content length cap
The `truncate_content` argument description shown to the model by `fetch-web-page` and `http-request` SHALL state the currently configured content length limit, not a hardcoded value.

#### Scenario: Description matches a non-default configuration
- **WHEN** the host has set `CODE_TALKER_MAX_CONTENT_LENGTH` to a value other than 20000
- **THEN** the tool schema description presented to the model states that configured value

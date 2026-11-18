## MODIFIED Requirements

### Requirement: Configurable raw body length cap
The system SHALL read the maximum number of bytes read off the wire before a response body is truncated from `config('code-talker.tools.web_fetcher.max_body_length')`, defaulting to 150000 when unset, instead of a hardcoded constant. The cap SHALL be enforced while the body is received, so a response larger than the cap is cut off rather than fully downloaded and then trimmed.

#### Scenario: Default body length cap applies when unconfigured
- **WHEN** `CODE_TALKER_MAX_BODY_LENGTH` is not set in the host's environment
- **THEN** `fetch-web-page` and `http-request` truncate the raw response body at 150000 bytes, matching current behavior

#### Scenario: Host-configured body length cap applies
- **WHEN** the host sets `CODE_TALKER_MAX_BODY_LENGTH` to a different value
- **THEN** `fetch-web-page` and `http-request` truncate the raw response body at that configured value instead of 150000

#### Scenario: The cap bounds the download, not just the result
- **WHEN** a server sends a body many times larger than the configured cap, compressed or not
- **THEN** reading stops once the cap is reached
- **AND** the memory used is proportional to the cap, not to the remote body

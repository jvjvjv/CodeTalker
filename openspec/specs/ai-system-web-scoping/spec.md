# ai-system-web-scoping Specification

## Purpose
TBD - created by openspec-sync-specs from change scope-http-request-per-ai-system. Update Purpose after archive.

## Requirements

### Requirement: AiSystem-scoped domain allow-list
An `AiSystem` MAY declare an `allowed_domains` list in its `web_tool_policy`. When present and non-empty, `fetch-web-page` and `http-request` calls made under that system SHALL be refused for any destination host not in the list, before any network request is attempted.

#### Scenario: Request to an allowed domain succeeds
- **WHEN** an `AiSystem` has `web_tool_policy.allowed_domains` containing `api.a.com`, and a chat bot using that system calls `http-request` for a URL on `api.a.com`
- **THEN** the request proceeds through `HostGate`'s existing checks as normal

#### Scenario: Request to a domain outside the allow-list is refused
- **WHEN** an `AiSystem` has `web_tool_policy.allowed_domains` containing `api.a.com`, and a chat bot using that system calls `http-request` or `fetch-web-page` for a URL on `api.b.com`
- **THEN** the request is refused before any DNS resolution or network call, with a caller-facing message naming the disallowed host

#### Scenario: Unscoped AiSystem remains unrestricted
- **WHEN** an `AiSystem` has no `web_tool_policy` (or `allowed_domains` is null/empty)
- **THEN** `fetch-web-page` and `http-request` calls under that system are subject only to `HostGate`'s existing private-host checks, unchanged from current behavior

### Requirement: AiSystem-scoped credentials
An `AiSystem` SHALL be able to declare a `credentials` map (host → header map) in its `web_tool_policy`. When present, the system SHALL attach the matching entry to outbound requests made under that system — both `http-request` and `fetch-web-page`, since both share the same underlying request path and neither exposes attached credentials to the model.

#### Scenario: Per-system credential is attached
- **WHEN** an `AiSystem`'s `web_tool_policy.credentials` includes an entry for the destination host of an `http-request` call
- **THEN** those headers are merged into the outbound request after model-supplied headers are filtered, and are never present in the tool's returned output

#### Scenario: Per-system credential is not visible to other systems
- **WHEN** two `AiSystem` records both have `http-request` allowed, and only one has a `web_tool_policy.credentials` entry for a given host
- **THEN** only requests made under the system with that entry attach the credential; the other system's requests to the same host attach no credential from this source

#### Scenario: Falls back to global config when unset
- **WHEN** an `AiSystem`'s `web_tool_policy` has no credential entry for the destination host (or no `web_tool_policy` at all)
- **THEN** `WebFetcher` falls back to `config('code-talker.tools.http_request.credentials')` for that host, unchanged from current behavior

#### Scenario: Per-system credential takes precedence over global config
- **WHEN** both an `AiSystem`'s `web_tool_policy.credentials` and the global config declare a credential for the same host
- **THEN** the per-system credential is attached, not the global one

### Requirement: Redirects re-derive scoping per hop
A redirect during an `http-request` or `fetch-web-page` call SHALL re-check the allow-list and re-resolve credentials for the new host at each hop, never carrying the original host's allowance or credentials to a different host.

#### Scenario: Redirect to a disallowed host is refused
- **WHEN** a request to an allowed domain redirects to a domain outside the `AiSystem`'s `allowed_domains`
- **THEN** the redirect is not followed and the call is refused

#### Scenario: Redirect does not carry credentials to a new host
- **WHEN** a request to a host with a configured credential redirects to a different host
- **THEN** the redirected request does not attach the original host's credential

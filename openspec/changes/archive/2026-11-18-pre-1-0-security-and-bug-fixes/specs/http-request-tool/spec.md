## ADDED Requirements

### Requirement: A URL carrying userinfo is refused

Both tools SHALL refuse a URL whose authority contains userinfo (`user@host` or `user:pass@host`). Credentials reach a request only through headers.

#### Scenario: A token in the URL authority

- **WHEN** either tool is invoked for `https://secret@api.example.com/`
- **THEN** it returns an error stating that credentials in the URL are not accepted
- **AND** no request is made and the URL is not logged with its userinfo intact

### Requirement: Policy declarations from an external MCP caller cannot reach private hosts

When a tool runs without a conversation — the external MCP transport — a `request_policy` that sets `allow_private_hosts` to `true` SHALL be refused before any network call. Private hosts are reachable only from a conversation.

#### Scenario: An MCP client declares private access

- **WHEN** `http-request` or `fetch-web-page` is invoked through the external MCP transport with `request_policy.allow_private_hosts: true` for a loopback URL
- **THEN** it returns an error stating that private hosts cannot be declared on this transport
- **AND** no request is made

#### Scenario: An MCP client fetches a public host

- **WHEN** either tool is invoked through the external MCP transport for a public URL with no private declaration
- **THEN** the request proceeds as before

### Requirement: A redirect-induced method change is not a policy violation

When a `301`, `302`, or `303` response causes the next hop to be issued as `GET`, that `GET` SHALL be exempt from the caller's `allowed_methods` declaration, which governs the method the caller asked for.

#### Scenario: A POST is redirected

- **WHEN** `http-request` is invoked with `method` `POST`, `allowed_methods` of `["POST"]`, and the server responds `302`
- **THEN** the redirect destination is requested with `GET`
- **AND** the request is not refused on method

## MODIFIED Requirements

### Requirement: Private and loopback hosts are refused unless the policy permits them

The host gate SHALL be one implementation shared by `http-request` and `fetch-web-page`. It SHALL refuse a URL whose host resolves to a loopback, link-local, private (RFC1918), shared-address (`100.64.0.0/10`), IETF-protocol (`192.0.0.0/24`), benchmarking (`198.18.0.0/15`), multicast (`224.0.0.0/4`), site-local (`fec0::/10`), NAT64 (`64:ff9b::/96`), or 6to4 (`2002::/16`) address unless the governing policy sets `allow_private_hosts` to `true`. When the policy declares a non-empty `allowed_hosts`, it SHALL refuse any URL whose host is not in that list. A host name that does not resolve SHALL be treated as private, so an unresolvable host fails the gate rather than slipping past it; it SHALL NOT be assigned a substitute address. `localhost` SHALL be resolved like any other name.

#### Scenario: Private host with no permission declared

- **WHEN** either tool is invoked for `http://127.0.0.1:8000/internal` or a host resolving to `10.0.0.5`, with `allow_private_hosts` absent or `false`
- **THEN** it returns an error response naming the refused host
- **AND** no HTTP request is made

#### Scenario: Private host explicitly permitted

- **WHEN** either tool is invoked for a loopback URL with `allow_private_hosts` set to `true`
- **THEN** the request proceeds

#### Scenario: Host outside a declared allow-list

- **WHEN** `allowed_hosts` is `["api.example.com"]` and the URL host is `other.example.com`
- **THEN** it returns an error response
- **AND** no HTTP request is made

#### Scenario: Non-http scheme is refused regardless of policy

- **WHEN** either tool is invoked with a `file://`, `ftp://`, or `gopher://` URL, with any policy including a fully permissive one
- **THEN** it returns an error response stating the URL must be http or https
- **AND** no request is made

#### Scenario: Method restriction applies only where declared

- **WHEN** a policy carries no method restriction, as `fetch-web-page` always builds it
- **THEN** the gate does not refuse on method
- **AND** `http-request` still refuses its own empty `allowed_methods` declaration before the gate is consulted

#### Scenario: A shared-address or multicast host

- **WHEN** either tool is invoked for a host resolving to `100.100.100.200` or `224.0.0.1`, with `allow_private_hosts` absent or `false`
- **THEN** it returns an error response naming the refused host
- **AND** no HTTP request is made

#### Scenario: An unresolvable host under a permissive policy

- **WHEN** either tool is invoked for a host name with no address records, with `allow_private_hosts` set to `true`
- **THEN** the request fails to connect
- **AND** no connection is made to the loopback address

### Requirement: The connection uses the address the gate validated

Both tools SHALL resolve a host once, during the gate check, and SHALL pin that address into the connection so no second resolution can occur between validation and connect. Pinning SHALL be applied per redirect hop, using each hop's own validated address. A host that did not resolve SHALL NOT be pinned to any address.

#### Scenario: The validated address is pinned

- **WHEN** either tool issues a request to a host that passed the gate
- **THEN** the outgoing request carries a cURL resolve entry mapping that host and port to the address the gate resolved

#### Scenario: A rebinding host cannot move between validation and connect

- **WHEN** a host resolves to a public address at gate time and to a private address immediately afterwards
- **THEN** the connection still targets the public address the gate validated
- **AND** the private address is never contacted

#### Scenario: Each redirect hop pins its own address

- **WHEN** a permitted request redirects to a second permitted host
- **THEN** the second request pins the address resolved for the second host, not the first

#### Scenario: No address is pinned for an unresolvable host

- **WHEN** a host passed the gate only because the policy permits private hosts, and it did not resolve
- **THEN** the outgoing request carries no resolve entry for it

### Requirement: The caller cannot set authentication or hop-by-hop headers

The tool SHALL accept caller-supplied request headers but SHALL strip, case-insensitively, `host` and the hop-by-hop headers `connection`, `keep-alive`, `transfer-encoding`, `te`, `trailer`, `upgrade`, `proxy-connection`, and `proxy-authorization`. It SHALL treat `authorization`, `cookie`, `x-api-key`, `api-key`, `x-auth-token`, `x-access-token`, `x-session-token`, `x-goog-api-key`, and `x-amz-security-token` as credential headers, which the caller may set only where the governing policy permits credential headers, and which travel only while the request's origin — scheme, host, and port — is the origin the caller named, so a same-host redirect from `https` to `http` or to another port does not carry them. On a redirect hop to a different origin the tool SHALL forward only an allow-list of benign headers (`accept`, `accept-language`, `content-type`, `if-none-match`, `if-modified-since`, `range`, `x-request-id`). A header name that is not an RFC 7230 token, or a value containing control characters, SHALL be dropped and reported, not sent and not thrown. Package-owned headers, including the `WebScraperUserAgent` `User-Agent`, SHALL take precedence over a caller-supplied value of the same name. The response SHALL report which headers were stripped.

#### Scenario: Caller supplies an Authorization header

- **WHEN** the tool is invoked with a `headers` object containing `Authorization`
- **THEN** the outbound request does not carry that header
- **AND** the tool's response lists `Authorization` among the stripped headers

#### Scenario: Caller supplies an ordinary header

- **WHEN** the tool is invoked with a `headers` object containing `Content-Type` or `X-Request-Id`
- **THEN** the outbound request carries those headers unchanged

#### Scenario: Caller attempts to override the User-Agent

- **WHEN** the tool is invoked with a `headers` object containing `User-Agent`
- **THEN** the outbound request carries the `WebScraperUserAgent` value, not the caller's

#### Scenario: Caller supplies an API-key header on an unrestricted system

- **WHEN** the tool is invoked with a `headers` object containing `X-Api-Key` and the system's policy does not permit credential headers
- **THEN** the outbound request does not carry that header
- **AND** the response lists it among the stripped headers

#### Scenario: A permitted custom header does not follow a cross-host redirect

- **WHEN** the tool is invoked with a caller header outside the benign allow-list and the first host redirects to a different host
- **THEN** the request to the second host does not carry that header

#### Scenario: A malformed header name

- **WHEN** the tool is invoked with a header line whose name contains a space
- **THEN** the header is not sent, is listed among the stripped headers, and the request otherwise proceeds

### Requirement: Credentials are supplied by host configuration, never by the caller

The tool SHALL attach credential headers from host configuration — the `code-talker.tools.http_request.credentials` map or the system's web tool policy — matched on the URL's host. These SHALL be applied after caller-header filtering, so a configured credential can set a header the caller is forbidden to set. Configured credentials SHALL be attached only when the request is made over `https`, or the host is a loopback address, so a plain-`http` request or an `https`-to-`http` redirect never carries them in clear text. Credential values SHALL NOT appear in the tool's inputs or in its returned response.

#### Scenario: Configured host receives its credentials

- **WHEN** `credentials` maps `api.example.com` to an `Authorization` header and the tool requests `https://api.example.com/v1/things`
- **THEN** the outbound request carries that `Authorization` header
- **AND** the returned response does not contain the credential value

#### Scenario: Unconfigured host receives none

- **WHEN** the tool requests a host with no entry in `credentials`
- **THEN** the outbound request carries no credential header

#### Scenario: A credentialed host over plain HTTP

- **WHEN** `credentials` maps `api.example.com` and the tool requests `http://api.example.com/v1/things`
- **THEN** the outbound request carries no credential header

#### Scenario: A credentialed loopback over plain HTTP

- **WHEN** `credentials` maps `127.0.0.1` and the tool requests `http://127.0.0.1:8000/`
- **THEN** the outbound request carries the configured header

### Requirement: Size caps and failure reporting match the existing fetch tool

The tool SHALL read at most the configured raw body length from the response, enforced while the body is being received so that neither the wire bytes nor their decompressed form are buffered beyond the cap, and SHALL truncate returned content to the configured content length unless `truncate_content` is `false`, flagging the response when truncation occurred. Structured content SHALL be truncated after decoding, and flagged, rather than returned as malformed structure. A connection failure SHALL produce an error response naming the URL. A non-2xx response SHALL produce an error response naming the URL and the HTTP status; for `http-request` it SHALL also carry the decoded response body, subject to the same caps, because an API's error body is what the caller needs.

#### Scenario: Oversized response

- **WHEN** a response body exceeds the content cap and `truncate_content` is not `false`
- **THEN** `content` is cut to the cap
- **AND** the response's `truncated` flag is true

#### Scenario: Connection failure

- **WHEN** the request cannot connect before receiving a response
- **THEN** the tool returns an error naming the URL
- **AND** the failure is logged

#### Scenario: Non-2xx response

- **WHEN** the server responds `404` or `500`
- **THEN** the tool returns an error naming the URL and the HTTP status
- **AND** for `http-request`, the decoded body is included

#### Scenario: A response larger than the body cap

- **WHEN** a server streams a body several times the configured raw body length
- **THEN** the download stops once the cap is reached and the truncated body is processed
- **AND** process memory does not grow with the size of the remote body

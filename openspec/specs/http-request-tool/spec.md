# http-request-tool Specification

## Purpose
Gives the agent a general-purpose HTTP request tool that runs only under a request policy the caller declares, refuses private hosts unless that policy permits them, re-validates every redirect, and takes credentials from host configuration rather than from the caller. The page-fetch tool shares the same host gate.
## Requirements

### Requirement: The agent can make general HTTP requests

The package SHALL provide a chat-bot tool named `http-request` that issues an HTTP request and returns the decoded response. It SHALL support the `GET`, `POST`, `PUT`, `PATCH`, and `DELETE` methods and an optional request body. It SHALL be discoverable by `ChatBotToolRegistry`, gated in the local chat loop by `AiSystem::allowed_tools`, and registered on `CodeTalkerServer` for the external MCP transport with all of its methods available.

#### Scenario: Successful GET returning JSON

- **WHEN** the tool is invoked with `method` `GET` and a URL that responds `200` with `application/json`
- **THEN** the response is successful
- **AND** its `content` is the decoded JSON structure, not a JSON string
- **AND** it reports the request's `status` and `content_type`

#### Scenario: POST with a request body

- **WHEN** the tool is invoked with `method` `POST`, a `body`, and a `Content-Type` of `application/json`
- **THEN** the request is sent with that method and body
- **AND** the decoded response is returned

#### Scenario: Tool is available without a conversation

- **WHEN** the tool is invoked through the external MCP transport, where `ToolContext::conversation` is `null`
- **THEN** the request proceeds
- **AND** the outbound `User-Agent` is the `WebScraperUserAgent` value for an unknown bot name

### Requirement: The caller must declare a request policy, and the tool fails closed without one

The tool SHALL require a `request_policy` input declaring at minimum a non-empty `allowed_methods` list. When `request_policy` is absent, is not an object, or declares no methods, the tool SHALL refuse the request before any network call and SHALL return an error naming the field the caller must supply.

#### Scenario: No policy declared

- **WHEN** the tool is invoked with a valid URL and method but no `request_policy`
- **THEN** it returns an error response
- **AND** the error states that `request_policy.allowed_methods` must be declared
- **AND** no HTTP request is made

#### Scenario: Policy declared with an empty method list

- **WHEN** the tool is invoked with `request_policy.allowed_methods` set to an empty list
- **THEN** it returns an error response
- **AND** no HTTP request is made

#### Scenario: Method outside the declared policy

- **WHEN** the tool is invoked with `method` `DELETE` and `request_policy.allowed_methods` of `["GET"]`
- **THEN** it returns an error response quoting the methods the caller itself declared
- **AND** no HTTP request is made

#### Scenario: Method inside the declared policy

- **WHEN** the tool is invoked with `method` `DELETE` and `request_policy.allowed_methods` containing `DELETE`
- **THEN** the request proceeds

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

### Requirement: The fetch tool gates hosts through the same declared policy

`fetch-web-page` SHALL accept an optional `request_policy` input carrying `allow_private_hosts` and `allowed_hosts`, with the same meaning those fields have for `http-request`. It SHALL NOT accept `allowed_methods`, being GET-only. When no `request_policy` is supplied, the tool SHALL behave exactly as one declaring `allow_private_hosts: false` with no host allow-list: public hosts only.

#### Scenario: No policy declared reaches only public hosts

- **WHEN** `fetch-web-page` is invoked for a public URL with no `request_policy`
- **THEN** the page is fetched and returned as before

#### Scenario: No policy declared refuses a private host

- **WHEN** `fetch-web-page` is invoked for `http://127.0.0.1/`, a host resolving to an RFC1918 address, or a link-local address, with no `request_policy`
- **THEN** no request is made
- **AND** the tool returns an error naming the host and the `request_policy.allow_private_hosts` field to declare
- **AND** the error does not mention `allowed_methods`, which this tool has no input for

#### Scenario: Private host reached after an explicit declaration

- **WHEN** `fetch-web-page` is invoked for a loopback URL with `request_policy.allow_private_hosts` set to `true`
- **THEN** the page is fetched

#### Scenario: Host allow-list is honored

- **WHEN** `request_policy.allowed_hosts` is declared and the URL host is not in it
- **THEN** no request is made and the tool returns an error

### Requirement: The fetch tool re-validates every redirect hop

`fetch-web-page` SHALL NOT follow redirects automatically. It SHALL re-run the scheme check and the host gate against each redirect destination before issuing it, capped at 5 hops, and SHALL report the final destination as the response `url`.

#### Scenario: Public page redirects into a private network

- **WHEN** a public URL responds `302` with a `Location` pointing at a loopback or link-local address, and no `request_policy` permits it
- **THEN** the redirect destination is never requested
- **AND** the tool returns an error stating the request was redirected and naming the refused destination

#### Scenario: Permitted redirect is followed

- **WHEN** a public URL redirects to another public destination, including via a relative `Location`
- **THEN** the destination is fetched and its readable text returned
- **AND** the reported `url` is the final destination

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

### Requirement: Every redirect hop is re-validated against the declared policy

The tool SHALL NOT follow redirects automatically. It SHALL re-run the scheme check and the full declared-policy gate against each redirect destination before issuing the next request, and SHALL abandon the request after at most 5 hops. Credential headers SHALL be re-derived from each hop's own host, so a redirect cannot carry the previous host's credentials. A refused hop SHALL produce an error naming the destination.

#### Scenario: Public host redirects into a private network

- **WHEN** a permitted public URL responds `302` with a `Location` pointing at a loopback, link-local, or private address, and `allow_private_hosts` is not `true`
- **THEN** the redirect destination is never requested
- **AND** the tool returns an error stating the request was redirected and naming the refused destination

#### Scenario: Redirect outside a declared host allow-list

- **WHEN** `request_policy.allowed_hosts` is declared and a permitted URL redirects to a host outside it
- **THEN** the redirect destination is never requested
- **AND** the tool returns an error

#### Scenario: Permitted redirect is followed

- **WHEN** a URL redirects to a destination that passes the same gate, including a relative `Location`
- **THEN** the destination is requested and its decoded body is returned
- **AND** the reported `url` is the final destination rather than the original

#### Scenario: Redirect does not carry the previous host's credentials

- **WHEN** a host with configured credentials redirects to a different host
- **THEN** the request to the second host does not carry the first host's credential header

#### Scenario: Redirect loop

- **WHEN** a URL redirects to itself indefinitely
- **THEN** the request is abandoned after the hop limit
- **AND** the tool returns an error naming the redirect limit

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

### Requirement: Responses are decoded by content type, and binary is refused

The tool SHALL decode the response body according to its `Content-Type`: JSON (`application/json` and `+json` suffixes) to a structure; XML (`application/xml`, `text/xml`, and `+xml` suffixes) to a structure, parsed with external entity loading disabled and `LIBXML_NONET` set; HTML and XHTML to readable text, honoring `keep_html` and `target_selector` as `fetch-web-page` does; and other `text/*` types to whitespace-normalized text. Any other content type SHALL be refused with an error naming it.

#### Scenario: XML response

- **WHEN** the response carries `application/xml`
- **THEN** `content` is the parsed structure
- **AND** an XML document declaring an external entity does not cause that entity to be resolved

#### Scenario: HTML response with a target selector

- **WHEN** the response carries `text/html` and `target_selector` is supplied
- **THEN** `content` is the readable text of the matched element only
- **AND** a selector matching nothing returns an error naming the selector

#### Scenario: Malformed JSON

- **WHEN** the response declares `application/json` but the body does not parse
- **THEN** the response is not an error
- **AND** `content` is the raw text, accompanied by a decode-failure note

#### Scenario: Binary response

- **WHEN** the response carries `image/png`, `application/pdf`, or `application/octet-stream`
- **THEN** the tool returns an error naming that content type
- **AND** no encoded body is returned

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

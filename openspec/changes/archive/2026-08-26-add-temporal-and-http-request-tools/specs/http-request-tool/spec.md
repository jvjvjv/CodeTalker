## ADDED Requirements

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

The tool SHALL refuse a URL whose host resolves to a loopback, link-local, or private (RFC1918) address unless `request_policy.allow_private_hosts` is `true`. When `request_policy.allowed_hosts` is present and non-empty, the tool SHALL refuse any URL whose host is not in that list.

#### Scenario: Private host with no permission declared

- **WHEN** the tool is invoked for `http://127.0.0.1:8000/internal` or a host resolving to `10.0.0.5`, with `allow_private_hosts` absent or `false`
- **THEN** it returns an error response naming the refused host
- **AND** no HTTP request is made

#### Scenario: Private host explicitly permitted

- **WHEN** the tool is invoked for a loopback URL with `request_policy.allow_private_hosts` set to `true`
- **THEN** the request proceeds

#### Scenario: Host outside a declared allow-list

- **WHEN** `request_policy.allowed_hosts` is `["api.example.com"]` and the URL host is `other.example.com`
- **THEN** it returns an error response
- **AND** no HTTP request is made

#### Scenario: Non-http scheme is refused regardless of policy

- **WHEN** the tool is invoked with a `file://`, `ftp://`, or `gopher://` URL, with any `request_policy` including a fully permissive one
- **THEN** it returns an error response stating the URL must be http or https
- **AND** no request is made

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

The tool SHALL accept caller-supplied request headers but SHALL strip, case-insensitively, `authorization`, `proxy-authorization`, `cookie`, `host`, and the hop-by-hop headers `connection`, `keep-alive`, `transfer-encoding`, `te`, `trailer`, `upgrade`, and `proxy-connection`. Package-owned headers, including the `WebScraperUserAgent` `User-Agent`, SHALL take precedence over a caller-supplied value of the same name. The response SHALL report which headers were stripped.

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

### Requirement: Credentials are supplied by host configuration, never by the caller

The tool SHALL attach credential headers from `code-talker.tools.http_request.credentials`, a map of host to header set, matched on the URL's host. These SHALL be applied after caller-header filtering, so a configured credential can set a header the caller is forbidden to set. Credential values SHALL NOT appear in the tool's inputs or in its returned response.

#### Scenario: Configured host receives its credentials

- **WHEN** `credentials` maps `api.example.com` to an `Authorization` header and the tool requests `https://api.example.com/v1/things`
- **THEN** the outbound request carries that `Authorization` header
- **AND** the returned response does not contain the credential value

#### Scenario: Unconfigured host receives none

- **WHEN** the tool requests a host with no entry in `credentials`
- **THEN** the outbound request carries no credential header

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

The tool SHALL read at most 150,000 characters from the response body, and SHALL truncate returned content to 20,000 characters unless `truncate_content` is `false`, flagging the response when truncation occurred. Structured content SHALL be truncated after decoding, and flagged, rather than returned as malformed structure. A connection failure and a non-2xx response SHALL each produce an error response naming the URL and, for the latter, the HTTP status.

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

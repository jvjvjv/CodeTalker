## ADDED Requirements

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

Both tools SHALL resolve a host once, during the gate check, and SHALL pin that address into the connection so no second resolution can occur between validation and connect. Pinning SHALL be applied per redirect hop, using each hop's own validated address.

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

## MODIFIED Requirements

### Requirement: Private and loopback hosts are refused unless the policy permits them

The host gate SHALL be one implementation shared by `http-request` and `fetch-web-page`. It SHALL refuse a URL whose host resolves to a loopback, link-local, or private (RFC1918) address unless the governing policy sets `allow_private_hosts` to `true`. When the policy declares a non-empty `allowed_hosts`, it SHALL refuse any URL whose host is not in that list. A host name that does not resolve SHALL be treated as private, so an unresolvable host fails the gate rather than slipping past it.

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

## ADDED Requirements

### Requirement: MCP servers are managed through a service

The package SHALL expose a service that performs every write operation on remote MCP server records and triggers catalog syncs. It SHALL publish its validation rules so a host can reuse them in its own form requests.

#### Scenario: Creating a server syncs its catalog

- **WHEN** a host creates a server through the service
- **THEN** the record is stored with its auth configuration encrypted
- **AND** a catalog sync is attempted after the record is committed
- **AND** a failed sync is reported in the result and recorded on the server, rather than thrown or rolling back the record

#### Scenario: Validation rules are reusable

- **WHEN** a host builds a form request for creating or updating a server
- **THEN** it can use the service's published rules, which reject a malformed slug, a non-HTTP(S) URL, an unknown auth type, a non-HTTP transport, and a timeout outside the allowed range

#### Scenario: Auth supplied as a JSON string

- **WHEN** a host supplies the auth configuration as a JSON string
- **THEN** it is decoded before persistence, the same way other JSON attributes are handled

#### Scenario: The slug is immutable after creation

- **WHEN** a host updates an existing server
- **THEN** the stored slug is unchanged regardless of what was submitted, so existing grants keep resolving

#### Scenario: Syncing on demand

- **WHEN** a host asks the service to sync one server, or all enabled servers
- **THEN** each server is synced independently, and the result reports, per server, how many tools were stored, how many are unrepresentable, and any failure

#### Scenario: Deleting a server reports affected systems

- **WHEN** a server is deleted
- **THEN** the server and its catalog are removed from use
- **AND** the service reports how many AI systems had granted one of its tools
- **AND** those systems' allowed-tool lists are left unchanged, and the now-unknown names are ignored

#### Scenario: Listing servers shows sync health

- **WHEN** a host lists servers through the service
- **THEN** each server includes its last sync time, its last sync error, and its tools with their exposed names and availability, and the auth configuration is not included

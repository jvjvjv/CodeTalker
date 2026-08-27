## MODIFIED Requirements

### Requirement: Persona access rules are enforced by the service

Rules that previously lived in the HTTP layer SHALL be enforced where the turn is started, so they cannot be lost by a host writing its own controller.

#### Scenario: An inactive persona is refused

- **WHEN** a conversation is started for an inactive persona
- **THEN** it fails rather than opening one

#### Scenario: A persona requiring visitor identity is given none

- **WHEN** a conversation is started for a persona requiring visitor identity without a name and email
- **THEN** it fails with an error naming the requirement

#### Scenario: The chat hash stays current

- **WHEN** a conversation is continued
- **THEN** its shareable hash is present and current, as it was when the package owned the endpoint

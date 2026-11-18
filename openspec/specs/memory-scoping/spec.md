# memory-scoping Specification

## Purpose
Defines whose memories a conversation may read and write: which conversations are analysed for memories, which identity a memory belongs to, and which identity is used when memories are injected into a prompt.

## Requirements

### Requirement: A memory belongs to exactly one identity

Every stored memory SHALL be scoped to either a user id or a visitor email, never both and never neither. A memory extracted from a conversation with a user id SHALL store no visitor email, even when the conversation also recorded one.

#### Scenario: An authenticated user's conversation also carries an email

- **WHEN** memories are extracted from a conversation with a user id and a visitor email
- **THEN** each memory is stored with the user id and a null visitor email

#### Scenario: A visitor claims an authenticated user's email

- **WHEN** a conversation identified only by visitor email is analysed or has memories injected, and that email matches an authenticated user's memories
- **THEN** none of the user's memories are read, updated, or deactivated

#### Scenario: A manually created memory must name an identity

- **WHEN** a host creates a memory through the management service with neither a user id nor a visitor email
- **THEN** validation fails naming the identity requirement

### Requirement: A conversation with no identity is not analysed

Memory extraction SHALL run only for a conversation that carries a user id or a visitor email. A conversation with neither SHALL be skipped before any provider call, and no memory query SHALL ever run without an identity constraint.

#### Scenario: An anonymous visitor asks the bot to forget something

- **WHEN** a conversation with no user id and no visitor email is completed
- **THEN** no extraction call is made
- **AND** no existing memory for the feature is modified or deactivated

#### Scenario: An operator run completes

- **WHEN** an operator run's conversation is marked completed
- **THEN** no extraction job is dispatched for it

### Requirement: Injection uses the conversation's identity

Memories injected into a system prompt SHALL be selected by the identity of the conversation being started or continued — the user passed by the caller, or the visitor email it recorded — and never by the ambient authenticated session.

#### Scenario: A visitor returns

- **WHEN** a conversation is started for a visitor whose email has memories for the feature
- **THEN** those memories appear in the prompt's learned-insights section

#### Scenario: A turn is driven outside a web request

- **WHEN** a host starts a conversation for a user from a queue or console context where no session is authenticated, or a different user is
- **THEN** the memories injected are that user's, not the session's

### Requirement: Visitor identity is unverified and documented as such

The package SHALL document that a visitor email is whatever the visitor typed, so visitor-scoped memories are only as trustworthy as the host's identity collection, and a host wanting verified scoping must authenticate the user.

#### Scenario: Reading the memory documentation

- **WHEN** a host developer reads the memory system section of the README
- **THEN** it states that visitor-email scoping is unverified and recommends authentication where memory confidentiality matters

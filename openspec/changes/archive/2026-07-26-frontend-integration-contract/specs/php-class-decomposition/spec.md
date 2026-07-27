## MODIFIED Requirements

### Requirement: Inertia props are unchanged

The chat-bot pages SHALL render the configured component names with the same prop keys and values as before the refactor, including the differences that already exist between `show()` and `showByHash()`. The component name is resolved from `code-talker.inertia.components`, whose defaults reproduce the previously hard-coded names. The prop set is the published contract documented in the README, not an implementation detail.

#### Scenario: Chat bot page rendered from session state

- **WHEN** `show()` renders the configured chat-bot component, `ai/ChatBot` by default
- **THEN** the props are exactly `bot`, `messages`, `history`, `messageUrl`, `resetUrl`, `switchUrl`, `statusUrl`, `warmupUrl`, `chatUrl`, `chatUrlBase`, `showIdentityForm`
- **AND** `showIdentityForm` is true only when there is no authenticated user, the bot requires visitor identity, and no stored conversation exists

#### Scenario: Chat bot page rendered from a chat hash

- **WHEN** `showByHash()` renders the configured chat-bot component
- **THEN** the props additionally include `chatHash`
- **AND** `showIdentityForm` is true only when there is no authenticated user, the bot requires visitor identity, and the resolved conversation has zero non-system messages

#### Scenario: Chat bot index

- **WHEN** `index()` renders the configured index component, `ai/ChatBotsIndex` by default
- **THEN** each bot carries `slug`, `name`, `description`, `new_chat_url`, `status_url`, and a `conversations` list of `title`, `updated_at`, `updated_at_human`, `is_stale`
- **AND** conversations are only included for an authenticated user

#### Scenario: Component names come from configuration

- **WHEN** a host sets `code-talker.inertia.components.chat_bot` or `.chat_bots_index`
- **THEN** the corresponding page renders that component instead of the default
- **AND** every prop keeps the key and value it had before

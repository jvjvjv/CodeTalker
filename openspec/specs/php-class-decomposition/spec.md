# php-class-decomposition Specification

## Purpose
TBD - created by archiving change refactor-large-php-classes. Update Purpose after archive.
## Requirements
### Requirement: Extracted collaborators live beside the class they came from

Oversized package classes SHALL be decomposed into collaborators placed in a namespace that identifies the class they were extracted from, rather than into a generic bucket. Collaborators for HTTP chat-bot flow SHALL live under `Jvjvjv\CodeTalker\Services\ChatBot\`, collaborators for the conversation turn under `Jvjvjv\CodeTalker\Services\ChatBot\Conversation\`, and collaborators for the `search-web` tool under `Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb\`. Single-use collaborators SHALL still get their own file.

A collaborator shared by more than one class has no single origin class, and SHALL instead live in a namespace named for the capability it provides, as a sibling of the package's other service namespaces. A shared collaborator SHALL NOT be placed inside a directory registered as a tool directory, because `DiscoversAiToolHandlers` walks those recursively and any class there that extends `Laravel\Mcp\Server\Tool` or implements `AiToolHandlerContract` registers itself as a tool.

#### Scenario: A responsibility is extracted from a large class

- **WHEN** a distinct responsibility is lifted out of `ChatBotController`, `AiChatBotConversationService`, or `SearchWebTool`
- **THEN** it is placed in one file in the namespace named for its origin class
- **AND** it is placed there even if it has exactly one caller

#### Scenario: A responsibility is extracted for use by more than one class

- **WHEN** the fetch-and-extract logic is lifted out of `FetchWebPageTool` so that both `fetch-web-page` and `http-request` can use it
- **THEN** it is placed under `Jvjvjv\CodeTalker\Services\Web\`, named for the capability rather than for `FetchWebPageTool`
- **AND** it is not placed under `Services/Mcp/Tools/ChatBot/`

#### Scenario: A shared collaborator does not become a phantom tool

- **WHEN** `ChatBotToolRegistry` discovers tools across the package and host-registered tool directories
- **THEN** the discovered tool set contains no shared collaborator class
- **AND** the set is exactly the tools declared with a `#[Name]` attribute

#### Scenario: Value objects follow the package's existing style

- **WHEN** an extracted piece is a data carrier rather than behavior
- **THEN** it is declared `final class` with promoted `public readonly` properties and named static factory methods, matching `Support\ToolContext` and `Services\RawExchange\RawExchangeFrame`

### Requirement: Preserved public surfaces

The refactor SHALL NOT change any signature that code outside the refactored class depends on.

#### Scenario: Conversation service is constructed positionally by tests

- **WHEN** `AiChatBotConversationService` is instantiated with `AgentFactory`, `AiMemoryService`, `ConversationUsageService`, `RawExchangeContext`, `AiSystemProviderConfigurator` in that positional order
- **THEN** construction succeeds
- **AND** no additional constructor parameter is required

#### Scenario: Conversation service guards remain overridable

- **WHEN** a subclass of `AiChatBotConversationService` overrides `protected function streamElapsedSeconds(float $startedAt): float` or `protected function clientAborted(): bool`
- **THEN** `continueConversation()` consults the subclass's override for every guard check
- **AND** the max-stream-duration and client-abort behaviors driven by those overrides are unchanged

#### Scenario: Controller actions keep their signatures

- **WHEN** routes in `routes/codetalker-chatbots.php` resolve to `ChatBotController`
- **THEN** `index`, `statuses`, `show`, `status`, `warmup`, `message`, `switch`, `reset`, `newChat`, and `showByHash` accept the same parameters and return the same response types as before

#### Scenario: Search tool keeps its MCP contract

- **WHEN** the MCP layer inspects `SearchWebTool`
- **THEN** its `#[Name('search-web')]` and `#[Description]` attributes, `schema(JsonSchema $schema): array`, and `handle(Request $request): Response|ResponseFactory` are unchanged
- **AND** its constructor still takes a single `ToolContext`

#### Scenario: Fetch tool keeps its MCP contract

- **WHEN** the MCP layer inspects `FetchWebPageTool`
- **THEN** its `#[Name('fetch-web-page')]` attribute, `schema(JsonSchema $schema): array`, and `handle(Request $request): Response|ResponseFactory` are unchanged
- **AND** its inputs are `url`, `keep_html`, `truncate_content`, `target_selector`, and `request_policy`
- **AND** the four inputs other than `request_policy` keep the names, types, and meanings they had before
- **AND** its constructor still takes a single `ToolContext`

#### Scenario: Fetch tool behavior is unchanged for public pages

- **WHEN** `fetch-web-page` fetches a page on a public host, with or without a declared `request_policy`
- **THEN** the successful response keys remain `url`, `title`, `content_type`, `content`, and `truncated`
- **AND** the error strings for an invalid URL, an empty body, a non-HTML content type, an unmatched selector, a connection failure, and a failed HTTP status are byte-identical to the ones the tool returned in 0.10.0

### Requirement: The browser SSE wire format is unchanged

`continueConversation()` SHALL yield the identical sequence of `data: {...}\n\n` lines it yielded before the refactor, for every turn outcome.

#### Scenario: Normal completed turn

- **WHEN** a turn streams text and reasoning deltas and finishes normally
- **THEN** the emitted events are the `model_loading` status line, the translated `content_block_delta` / `reasoning_block_delta` / `message_delta` / `message_stop` events in original order, and a final `data: [DONE]\n\n`

#### Scenario: Turn cut off by the max-stream-duration guard

- **WHEN** the elapsed-time guard trips mid-stream
- **THEN** the last emitted event is an error with `reason: max_stream_duration` and the configured duration in its message
- **AND** no `[DONE]` line follows it
- **AND** any text or reasoning streamed so far is still persisted as an `AiConversationMessage`

#### Scenario: Provider failure

- **WHEN** the agent stream throws
- **THEN** the last emitted event is an error carrying the exception message and `reason: provider_error`

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

### Requirement: Conversation session and cookie semantics are unchanged

Per-bot conversation state SHALL continue to live in the server-side session, mirrored only by the single `ai_chat_bot_current` cookie, with legacy per-bot cookies forgotten on sight.

#### Scenario: Legacy cookies are forgotten

- **WHEN** a request arrives carrying cookies matching `ai_chat_bot_conversations_{digits}`
- **THEN** each is queued for deletion
- **AND** no per-bot conversation cookie is set in the response

#### Scenario: History is capped and never written to a cookie

- **WHEN** stored state is persisted
- **THEN** at most 25 history entries are kept in the session
- **AND** only the current conversation's public id is written to `ai_chat_bot_current`, as an http-only, lax, 180-day cookie whose secure flag follows the request scheme

#### Scenario: Stored conversation belonging to another user is discarded

- **WHEN** the stored conversation has a `user_id` that does not match the current request's user
- **THEN** the per-bot session key is forgotten and no conversation is returned

### Requirement: Search tool output is unchanged

`search-web` SHALL return the same structured payload and the same rendered markdown as before the refactor, for the same inputs and the same HTTP responses.

#### Scenario: Successful multi-engine search

- **WHEN** `handle()` runs with a valid query
- **THEN** the structured response contains `query`, `page`, `engines`, `per_engine_limit`, `results`, `markdown`, `next_page_input`, and `next_actions` with unchanged values
- **AND** each engine's entry carries `source`, `query_url`, and `results`, capped at the per-engine limit

#### Scenario: One engine fails

- **WHEN** a single engine's fetch throws
- **THEN** that engine's entry is `{results: [], error: "Search failed on {engine}: {message}"}`, a warning is logged, and the other engines still return results

#### Scenario: Input validation

- **WHEN** the query is empty
- **THEN** an error response requiring a non-empty query is returned
- **AND** when unsupported engines are named, the error lists the unsupported and the supported engines

#### Scenario: API key selects the API strategy

- **WHEN** a configured API key (and, for Google, an engine id) is present
- **THEN** that engine fetches via its API and reports `source: api`
- **AND** otherwise it scrapes the public results page and reports `source: html`

### Requirement: Tool auto-discovery returns the same tool set

Adding collaborator classes inside the recursively scanned ChatBot tools directory SHALL NOT change which tools are discovered.

#### Scenario: Registry lists tools after extraction

- **WHEN** `ChatBotToolRegistry` discovers handlers from `Services/Mcp/Tools/ChatBot/` and its new `SearchWeb/` subdirectory
- **THEN** the discovered tool names are exactly those discovered before the refactor
- **AND** no collaborator class in `SearchWeb/` extends `Laravel\Mcp\Server\Tool` or implements `AiToolHandlerContract`

### Requirement: Persistence and logging are unchanged

The records written during a conversation turn SHALL be identical in count, ordering, and field values to those written before the refactor.

#### Scenario: Records written for a turn

- **WHEN** a turn runs to completion
- **THEN** one `AiLlmMessage` request row and one response row are written per continuation attempt with the same `turn_number` scheme (`N` for the first attempt, `N.attempt` thereafter)
- **AND** one `AiInteractionLog` is written with the same status, token, pricing-snapshot, and duration fields
- **AND** a `RawExchangeFrame` is pushed before each attempt's stream and popped in a `finally` block


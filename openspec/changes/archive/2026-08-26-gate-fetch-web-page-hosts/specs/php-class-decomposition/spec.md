## MODIFIED Requirements

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

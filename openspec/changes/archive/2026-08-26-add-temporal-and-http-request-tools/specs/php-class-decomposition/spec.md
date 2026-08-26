## MODIFIED Requirements

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

#### Scenario: Search tool keeps its MCP contract

- **WHEN** the MCP layer inspects `SearchWebTool`
- **THEN** its `#[Name('search-web')]` and `#[Description]` attributes, `schema(JsonSchema $schema): array`, and `handle(Request $request): Response|ResponseFactory` are unchanged
- **AND** its constructor still takes a single `ToolContext`

#### Scenario: Fetch tool keeps its MCP contract

- **WHEN** the MCP layer inspects `FetchWebPageTool` after its logic has been extracted into a shared collaborator
- **THEN** its `#[Name('fetch-web-page')]` attribute, `schema(JsonSchema $schema): array`, and `handle(Request $request): Response|ResponseFactory` are unchanged
- **AND** its four inputs remain `url`, `keep_html`, `truncate_content`, and `target_selector`
- **AND** its constructor still takes a single `ToolContext`

#### Scenario: Fetch tool behavior is unchanged by the extraction

- **WHEN** the existing `FetchWebPageToolTest` is run against the extracted implementation without being edited
- **THEN** every assertion passes
- **AND** the successful response keys remain `url`, `title`, `content_type`, `content`, and `truncated`
- **AND** the error strings for an invalid URL, an empty body, a non-HTML content type, an unmatched selector, a connection failure, and a failed HTTP status are byte-identical to the ones the tool returned before the extraction

## Why

A persona can only call tools that live in local PHP classes. There is no way to point an `AiSystem` at an external MCP server (for example MDN's public documentation server) and let the model use that server's tools during a turn. We want each remote tool to show up as its own tool, with its real input schema, granted or withheld by name exactly like a local tool. One opaque proxy tool per server is not acceptable: the model never sees the real schemas and ends up guessing at arguments.

The pieces are already installed. `laravel/mcp` ships an MCP client (`Client::web()`, `tools()`, `callTool()`). `laravel/ai` already turns that client's tool objects into agent tools (`Laravel\Ai\Tools\McpTool`), one per remote tool. The missing parts are the ones this package owns:
- a place to define servers
- a namespace so two servers' tools cannot collide
- `allowed_tools` filtering
- a refusal to expose a tool whose schema cannot be represented. Upstream silently substitutes an empty schema, which is exactly the argument-guessing failure above.
- containment, so a slow or broken remote server cannot abort a turn. Neither `laravel/ai`'s tool loop nor `ChatBotToolRegistry::dispatch()` catches a tool's exception today.

## What Changes

- Adds MCP server definitions as database records: slug, URL, encrypted auth, timeout, enabled flag, and last sync status. They are managed through a new `AiMcpServerManager` in `Services/Management/`.
- Adds a synced tool catalog. Syncing a server lists its tools once and stores each tool's name, description and input schema. Turns read the stored catalog and never ask the remote server for its tool list. Syncing runs when a server is created or updated, through `php artisan ai:sync-mcp-servers`, and on a daily schedule.
- Each remote tool is exposed under a namespaced name, `{server-slug}__{remote-name}`. That string is the tool's name on the wire, its key in the registry, and the entry an admin puts in `AiSystem::allowed_tools`. So granting one MDN tool and withholding the rest is just a matter of listing its name.
- Granted remote tools appear in `ChatBotToolRegistry::toApiTools()` and `toLaravelAiTools()` alongside local tools, and in the "all tools" listings the persona and operator managers build.
- Sync validates each tool's input schema. A tool whose schema cannot be represented is stored but never exposed.
- Remote calls are contained. A timeout, transport failure, protocol error or error result becomes a tool error the model can read, and the turn completes. After one connection failure or timeout, a server's remaining calls in the same turn fail immediately instead of waiting out the timeout again.
- HTTP transport only. Auth: none, a static bearer token, static headers, or OAuth client credentials.

No breaking changes. Hosts must publish and run two new migrations.

## Capabilities

### New Capabilities
- `remote-mcp-tools`: defining external MCP servers, syncing their tool catalogs, exposing each remote tool as an individually grantable tool, and invoking remote tools without letting a remote failure end the turn.

### Modified Capabilities
- `ai-management-services`: adds the requirement that MCP servers are managed through a service with reusable validation rules, sync, and delete reporting.

## Impact

- **Code**: two new models (`AiMcpServer`, `AiMcpServerTool`); a new `Services/Mcp/Remote/` directory (client factory, catalog sync, tool handler, name builder, per-turn server session); `ChatBotToolRegistry` gains a remote merge step, with its constructor signature unchanged; a new laravel/ai `Tool` adapter for remote tools; `AiMcpServerManager`; `SyncMcpServersCommand`.
- **Migrations**: `ai_mcp_servers` and `ai_mcp_server_tools`. Hosts must publish and migrate even if they never define a server, because the scheduled sync and the registry query both expect the tables.
- **Config**: a new top-level `remote_mcp` block (`enabled`, `max_timeout_seconds`, `default_timeout_seconds`). It is kept separate from the existing `mcp.*` block, which configures the package's own MCP server.
- **Schedule**: `ai:sync-mcp-servers` daily, disabled together with the rest by `code-talker.schedule = false`.
- **Dependencies**: none new. It uses `laravel/mcp`'s client, which is already a direct dependency.
- **Not in scope**:
  - the stdio transport
  - interactive or per-user OAuth (authorization-code flow)
  - grants set on the persona rather than its AiSystem
  - wildcard grants (`mdn__*`)
  - a failure breaker that persists across turns
  - recording remote MCP traffic in `ai_provider_exchanges`
  - requiring re-approval when a synced tool's definition changes

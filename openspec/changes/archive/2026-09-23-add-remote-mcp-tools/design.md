## Context

See proposal.md (Why). This section covers the parts of the current code that shape the design. Several of them differ from what a first read of the tool path suggests.

- **Tool discovery.** `ChatBotToolRegistry` finds tools only by scanning local directories (`DiscoversAiToolHandlers`). Tools are keyed by `name()`; when two share a name, the one that sorts first wins, and the package directory always sorts first. `AiSystem::allowed_tools` filters the result by name.
- **Grants are per AiSystem, not per persona.** A persona has only a `tools_enabled` boolean. An `AiOperator` can override its system's `allowed_tools`. So "grant a persona one MDN tool" means granting it on that persona's AiSystem. Per-persona grants would be a separate change.
- **The registry is built in four places, not only per turn:**
  - `AiPersonaConversationService::toolsFor()` (every chat turn)
  - `AiOperatorRunner` (every operator run)
  - `AiPersonaManager` and `AiOperatorManager`, which pass `$exposeAllDiscoveredTools` to build admin "available tools" listings

  A network call inside the constructor would also stall admin pages.
- **The laravel/mcp client.** The method is `Client::tools()`, not `listTools()`, and it pages through results. `callTool()` returns a `ToolResult` with `content`, `isError` and `structuredContent`. `ClientManager` is an in-memory map of named factories with nothing persisted, so it has no role here. `HttpTransport` sends through Laravel's `Http` facade, which means `Http::fake()` can drive every test. It keeps the MCP session id on the client instance, and its default timeout is 30 seconds.
- **laravel/ai already adapts MCP client tools.** `Laravel\Ai\Tools\McpTool` wraps one remote tool per agent tool. It does not fit as-is, for three reasons:
  - it prefixes every name with a flat `mcp_tools_`, so two servers' `search` tools collide
  - when a schema will not convert, it silently returns an empty schema
  - it lets exceptions from `callTool()` propagate

  `laravel/ai`'s `InvokesTools` calls `handle()` without a try/catch, so an uncaught exception from any tool ends the whole turn.
- **`BridgedTool::schema()` can't carry many remote schemas.** It deserializes each property on its own, so the root schema is lost. A `$ref` into root-level `$defs`, which is common in remote schemas, throws while laravel/ai is building the request.
- **OAuth in laravel/mcp.** The client does not store tokens. Its authorization-code flow keeps PKCE state in the session and needs callback routes, and this package ships no routes.

## Goals / Non-Goals

**Goals:**
- A turn never waits on a network call to learn which remote tools exist.
- A remote failure costs at most one timeout per server per turn, and never ends the turn.
- Remote tools go through the same `allowed_tools`, `toApiTools()` and `toLaravelAiTools()` path as local tools, so the persona, operator and admin paths pick them up without changes of their own.
- Local tool behavior is unchanged. `BridgedTool` and the discovered set pinned by `ChatBotToolRegistryTest` stay the same.

**Non-Goals:**
- The stdio transport, interactive OAuth, per-persona grants, wildcard grants, a breaker that persists across turns, capturing MCP traffic in `ai_provider_exchanges`, and approval workflows for definition drift. See the individual decisions for why each is left out.
- MCP resources and prompts. Only tools are consumed.

## Decisions

### 1. Server definitions live in a new table

`ai_mcp_servers` columns:
- `slug`: unique, `^[a-z0-9](?:[a-z0-9-]{0,22}[a-z0-9])?$`
- `name`, `url`
- `transport`: only `http` is accepted
- `auth`: `encrypted:array`
- `timeout_seconds`, `enabled`
- `last_synced_at`, `last_sync_error`
- soft deletes

Alternatives considered:
- *`config/code-talker.php` list.* Adding a server would take a deploy. Credentials would sit in env or config files, and admin UIs could not manage servers, which cuts against `Services/Management/`.
- *A JSON column on `AiSystem`, analogous to `web_tool_policy`.* One server is typically shared across several systems. A per-system copy would duplicate credentials and turn a single catalog into N catalogs that can drift apart.

The `ai-system-web-scoping` precedent does not carry over directly. It restricts URLs that the *model* chooses. An MCP server's URL is chosen by an admin. Per-system scoping for MCP is simply which exposed names appear in that system's `allowed_tools`.

### 2. The catalog is synced and persisted, never listed during a turn

`ai_mcp_server_tools` columns:
- `ai_mcp_server_id`
- `remote_name`
- `exposed_name`: unique
- `title`, `description`
- `input_schema`, `annotations`: json
- `representable`, `unrepresentable_reason`
- `definition_hash`, `synced_at`

`RemoteToolCatalogSync` pages through `tools()`, validates each schema, and upserts rows keyed by `(server, remote_name)`. It deletes rows for tools that have disappeared, and records success or failure on the server row.

A sync that fails part-way keeps the previous catalog. Rows are replaced inside a transaction only after the full listing has succeeded.

Sync is triggered by:
- the manager's create and update methods (after commit; a failure is reported, not thrown)
- `ai:sync-mcp-servers [slug]`
- a daily schedule

The registry runs one indexed query: `exposed_name IN (allowed_tools names containing "__")`, `representable = true`, and the server `enabled` and not deleted. In the `$exposeAllDiscoveredTools` case it drops the name filter.

Alternatives considered:
- *A cache with a TTL, filled lazily inside the turn.* When the cache is cold, the first turn pays for a handshake plus a listing, for every server, sequentially, because PHP has no concurrency here. An outage during that window removes the tools entirely. And a TTL still needs a network call from admin listing pages.
- *Listing inline with a short time budget.* Same problems, only smaller.

With a persisted catalog, "cold cache mid-turn" cannot happen. A server that has never synced contributes nothing. A server that is currently down still offers its last good catalog, and its calls fail through the containment in decision 5.

Drift: if `definition_hash` changes on a re-sync, the row is updated and a `RemoteMcpToolDefinitionChanged` event fires, so a host can alert on it. Requiring re-approval is deferred. It needs an approval state and a UI, and nothing in the specs depends on it.

### 3. Names are namespaced, and the namespaced name is the grant

`exposed_name = {slug}__{remote_name}`. The build steps:
1. Every character outside `[A-Za-z0-9_-]` in `remote_name` becomes `_`.
2. If the result is longer than 64 characters (the tool-name limit Anthropic and OpenAI enforce), it is truncated to 55 characters and suffixed with `_` plus the first 8 hex characters of `sha1(remote_name)`.

The builder is deterministic, so a re-sync reproduces the same names and existing grants keep working.

The wire name, registry key, `allowed_tools` entry and `dispatch()` key are all the same string. There is no mapping table for a grant to fall out of step with. The exposed name is also what the `AiLlmMessage` and interaction logs show.

A double underscore marks a remote tool. Package tool names use hyphens. If a host-registered local tool happens to produce the same exposed name, the local tool wins, the remote tool is left out of that registry, and a warning is logged. This extends the existing first-wins rule rather than inventing a new one.

If two remote names on one server sanitize to the same exposed name, both rows are stored, but the second is marked `representable = false` with a collision reason.

Alternatives considered:
- *Server-agnostic names*, as upstream does. Two servers collide.
- *Namespacing only at the wire and storing `{server, tool}` pairs in grants.* This changes the shape of `allowed_tools` from a list of strings, which breaks every host form built against `AiSystemManager::rules()`.

No wildcard grants. A sync can add tools to a server, and a wildcard would grant them without anyone deciding to.

### 4. Schemas are validated at sync time, and a non-representable tool is never exposed

Sync runs the same conversion laravel/ai uses on the root schema: `SchemaNormalizer::normalize()`, then `JsonSchema::fromArray()`. It requires the result to be an object type. If the conversion throws, or the result is not an object, the tool is stored with `representable = false` and the reason.

At turn time, remote tools are adapted by a new `RemoteBridgedTool` (a laravel/ai `Tool`). Its `schema()` uses that same root-level conversion, so `$defs` and `$ref` survive.

`BridgedTool` is not touched, so local tool schemas cannot regress. If the conversion somehow fails at turn time anyway, `RemoteBridgedTool` throws a contained error that the registry catches. The tool is left out of that turn's list, and the model is never sent an empty schema.

Alternative considered: using upstream `McpTool` directly. It keeps the flat prefix, falls back to an empty schema, and needs a live client object at list time. That last point would force a connection just to build the tool list.

### 5. Invocation is lazy and contained, with a breaker that lasts one turn

Each registry instance holds one `RemoteServerSession` per server that has at least one granted tool. The session builds its client (`RemoteMcpClientFactory`) and connects on the first call, then reuses that connection for the rest of the turn. The transport's destructor ends the MCP session.

The timeout is `min(server.timeout_seconds ?? remote_mcp.default_timeout_seconds (10), remote_mcp.max_timeout_seconds (30))`, applied with `withTimeout()`. It bounds the handshake and each call separately.

`RemoteToolHandler::call()` catches `Throwable`. The following all become `['error' => "Remote tool {exposed} failed: {reason}"]`:
- a transport error or timeout
- a JSON-RPC error
- a malformed result (`ClientException`)
- `isError: true`, which becomes `['error' => text]`

Results map the same way `McpTool::convertResult` maps them: `structuredContent` becomes the array; otherwise text content is joined under `['content' => …]`.

After any transport or protocol failure (a connection failure, a timeout, a non-2xx status, or a response that is not valid JSON-RPC or not a valid tool result), the session trips its breaker. Every later call to that server in the same registry instance returns an error immediately, without touching the network. A JSON-RPC error and an `isError` result do not trip it, because the server is answering. An HTTP 401 under `client_credentials` first refreshes the token and retries once; only a second failure trips.

In the worst case, one turn spends one timeout per granted server, instead of up to six loop steps × timeout.

The containment lives in `RemoteToolHandler::handle()`, which never throws. `ChatBotToolRegistry::dispatch()` routes remote handlers to it. Local tool exception behavior is left as it is, so that is not a hidden behavior change for local tools.

The catalog is read by `RemoteToolCatalog`. When no granted name contains `__`, it returns without running a query, so systems that grant no remote tools pay nothing. It also treats a `QueryException` as an empty catalog and logs it, so a host that upgraded but has not yet migrated still runs turns on local tools.

Deferred: a breaker that persists across turns (a cooldown in cache). `last_sync_error` plus the daily sync already make a persistent outage visible, and a shared breaker adds cache coupling and tuning without a demonstrated need.

Known interaction: while a remote call blocks, the turn generator is suspended and no heartbeat is sent. Heartbeats are emitted inside the provider's SSE read. The timeout cap bounds the silence, and the README will say so.

### 6. HTTP transport only, and stdio is never configurable from the database

On a chat turn served over HTTP, stdio means:
- spawning a process per turn under the web process
- running as the web user
- paying the child's cold start, often seconds for `npx`-launched servers, on every turn

More importantly, a command line stored in an admin-editable table lets the admin UI execute arbitrary commands on the host.

If stdio is ever added, it gets its own change. Commands would be declared in config, where deployment controls them. It would be limited to the operator/queue path, and server rows would reference commands by key.

### 7. Credentials belong to a server row

`auth` shapes:

```
{type: none}
{type: bearer, token}
{type: headers, headers: {…}}
{type: client_credentials, client_id, client_secret, scope?}
```

For `client_credentials`, the factory calls `WebClient::withOAuth()->oAuthClient()->clientCredentials()`. The resulting token is cached (the cache key is the server id plus a hash of the auth block) until shortly before it expires. On an HTTP 401, the token is refreshed once and the call retried once.

To give two AiSystems different identities against the same server, define two server rows with different slugs and credentials. Servers may list different tools for different identities, so "one catalog per credential" is the correct unit anyway.

The conversation's `ToolContext` (user id, visitor email) is never forwarded to a remote server.

Deferred: the authorization-code flow. It needs routes, which this package does not ship; somewhere to store refresh tokens; and a decision on whether tokens are per system or per end user. That is a separate change.

### 8. Configuration

A top-level `remote_mcp` config block:
- `enabled`, default `true`. When `false`, the registry skips the remote merge.
- `default_timeout_seconds`, default 10
- `max_timeout_seconds`, default 30

Every nested read uses an inline default, because a host with cached config skips `mergeConfigFrom`. The block is kept out of `mcp.*` because that block configures the package's *own* server and its `enabled` switch defaults to `false`. Sharing it would make the two features easy to confuse.

## Risks / Trade-offs

- **[Remote descriptions and results are untrusted text that goes straight into the model's context]** → Mitigations: grants are per tool; descriptions are synced (not fetched live) and changes to them fire an event. This is the same trust level as `fetch-web-page` output. Detailed threat notes go in the local CLAUDE.md security section, not the README or CHANGELOG.
- **[The stored catalog can go stale]** → The daily sync, a manual command, sync on update, and `last_synced_at` shown to admins. A stale definition fails as a contained tool error, never as a failed turn.
- **[Blocking remote calls stall a synchronous SSE stream with no heartbeat]** → The timeout is capped at 30 seconds, and detached turns (`dispatchTurn()`) are unaffected for the browser.
- **[Truncating long names with a hash makes them less readable]** → This only applies to names over 64 characters. The full `remote_name` stays stored and shown in admin listings.
- **[A host-registered local tool can shadow a remote tool]** → A warning is logged, and the double-underscore convention makes it unlikely unless someone does it on purpose.

## Migration Plan

This is additive. Hosts publish and run the two migrations (`--tag=code-talker-migrations`). Existing systems are unaffected until an admin defines a server, syncs it, and adds exposed names to `allowed_tools`. To roll back, remove the grants, or set `remote_mcp.enabled = false`, which removes every remote tool from every registry without touching the data.

## Open Questions

- Should the tool-invocation log mark remote calls with the server slug as a separate field, or is the namespaced name enough? The answer does not change behavior, and it can be decided when the logging code is written.

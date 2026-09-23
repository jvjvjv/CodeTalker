## 1. Data model and config

- [x] 1.1 Add migrations for `ai_mcp_servers` (soft deletes, unique slug) and `ai_mcp_server_tools` (unique `exposed_name`, unique `(ai_mcp_server_id, remote_name)`), published under `code-talker-migrations`; verify `php artisan migrate` in the test harness creates both tables
- [x] 1.2 Add `AiMcpServer` (`auth` cast `encrypted:array`, `hasMany` tools) and `AiMcpServerTool` models; verify with a model test that `auth` is stored encrypted and round-trips
- [x] 1.3 Add the `remote_mcp` config block (`enabled`, `default_timeout_seconds`, `max_timeout_seconds`), and read each value with an inline default; verify a test with the block unset resolves the defaults

## 2. Naming and schema validation

- [x] 2.1 Implement `ExposedToolName` (`{slug}__{sanitized}`, truncation to 64 characters with a hash suffix); verify with unit tests for the plain case, disallowed characters, overlong names, and determinism across calls
- [x] 2.2 Implement the representability check (`SchemaNormalizer` + `JsonSchema::fromArray` on the root, which must produce an object type); verify with tests covering a flat schema, a root `$defs` + `$ref` schema (accepted), and a non-object root schema (rejected with a reason) — laravel/ai's normalizer repairs most other malformed shapes rather than rejecting them

## 3. Client and catalog sync

- [x] 3.1 Implement `RemoteMcpClientFactory` (build a `WebClient`, apply `none`/`bearer`/`headers`/`client_credentials` auth, apply the capped timeout); verify with `Http::fake()` that the right headers are sent and that a `client_credentials` token is fetched once and reused from cache
- [x] 3.2 Implement `RemoteToolCatalogSync` (page through `tools()`, validate schemas, detect collisions, transactional replace, record `last_synced_at`/`last_sync_error`, dispatch `RemoteMcpToolDefinitionChanged` when a hash changes); verify with faked multi-page listings, a removed tool, a failure midway through the listing (previous catalog kept), and a changed description (event dispatched)
- [x] 3.3 Add `ai:sync-mcp-servers {slug?}` and register it on the daily schedule, gated by `code-talker.schedule`; verify with a command test and a schedule-registration test

## 4. Registry integration

- [x] 4.1 Implement `RemoteServerSession` (lazy connect, per-instance breaker that trips on transport or protocol failures but not on JSON-RPC errors or `isError` results) and `RemoteToolHandler` (map the result; catch `Throwable` → `['error' => …]`); verify with fakes for connection refused, timeout, malformed JSON-RPC, `isError`, structured content, and text content
- [x] 4.2 Merge remote handlers into `ChatBotToolRegistry` after local discovery (a granted-names query, or all exposable tools when `$exposeAllDiscoveredTools` is set; skipped when `remote_mcp.enabled` is false; local wins on a name collision, with a warning), leaving the constructor signature unchanged; verify with registry tests for granting one tool from a server, a disabled server, a never-synced server, the feature switch off, and a local/remote name collision, and confirm `ChatBotToolRegistryTest`'s pinned local set is unchanged
- [x] 4.3 Add `RemoteBridgedTool` (a laravel/ai `Tool` whose `schema()` uses the root-level conversion) and emit it from `toLaravelAiTools()` for remote handlers only; verify that a `$ref` schema produces the correct laravel/ai tool definition and that `BridgedTool` output for local tools is byte-identical to before
- [x] 4.4 Wrap remote dispatch in `ChatBotToolRegistry::dispatch()` so no remote exception propagates; verify the breaker end to end with a test that calls a down server's tools repeatedly and asserts no requests after the first failed attempt

## 5. Management service

- [x] 5.1 Implement `AiMcpServerManager` (`createRules()`/`updateRules()`, create/update with JSON decoding of `auth`, slug immutability, post-commit sync, `sync()`/`syncAll()` result reporting, delete reporting how many systems reference the server, a listing without `auth`); verify with a manager test that covers each scenario in the `ai-management-services` delta

## 6. End-to-end turn behavior

- [x] 6.1 Add a turn-level test (in the style of `ChatTurnLibraryTest`) in which a granted remote tool's server is down: the faked provider calls the tool, receives the error result, then replies, and the turn ends with `[DONE]` and a persisted assistant message
- [x] 6.2 Add a test showing that building a turn's tools with a synced but unreachable server makes no outbound request (request count on the fake server unchanged across registry construction)

## 7. Documentation

- [x] 7.1 README: add a "Remote MCP servers" subsection under Tool Registration (defining a server, syncing, granting by exposed name, failure behavior, the timeout cap and its heartbeat interaction, no stdio) and document the `remote_mcp` config keys; verify by rereading against the spec that every externally visible behavior is described
- [x] 7.2 CLAUDE.md: add the remote tool path to the Tool System architecture notes (the stored catalog, and why the registry must not make network calls), and add to the local Security Notes that remote descriptions and results are untrusted prompt input and that definition drift is only signaled, not gated
- [x] 7.3 Run `composer test`, and confirm the full suite passes with the new tests included

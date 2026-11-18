## 1. Install-blocking and schema

- [x] 1.1 Add a migration creating `ai_conversations.uuid` (string 36, nullable, unique) with a chunked per-row backfill, and in the same migration null `visitor_email` on `ai_feature_memories` rows that have a `user_id`; verify `AiPersonaConversationServiceTest` and `ChatTurnLibraryTest` pass after removing their hand-added `uuid` column setup.
- [x] 1.2 Guard `AiConversation::generateChatHash()` so a blank `uuid` is assigned before hashing; verify a test that creates a row with `uuid = null` and calls `generateChatHash()` twice on two rows yields distinct hashes.
- [x] 1.3 Make the default-prompt seed migration Postgres-safe by advancing the sequence when `DB::getDriverName() === 'pgsql'`; verify a unit test pins the `setval` SQL in pretend mode for the pgsql driver and that `AiSystemPromptManager` create still works on SQLite.
- [ ] 1.4 Add the `uuid` migration row to README "Migrating between versions" (no CHANGELOG — release commit); verify the README table renders the new row.

## 2. Error disclosure

- [x] 2.1 Add a fixed-message map for turn error reasons (`provider_error`, `max_duration`, `persona_unavailable`, `system_unavailable`) and use it in `AiPersonaConversationService` and `RunConversationTurnJob`; log the exception; verify a test that makes the agent throw an exception whose message contains a URL asserts the yielded `error.message` does not contain it and the log does.
- [x] 2.2 `AiModelReadinessService::statusForSystem()` returns a fixed unavailable message and logs the exception; verify `ChatBotStatusResolver` test asserts no exception text in the status payload.
- [x] 2.3 `SearchWebTool::searchEngine()` reports a generic transport failure to the model; verify a test with an engine throwing a message containing `?key=` asserts the key is absent from the tool result.
- [x] 2.4 `ProviderModelsClient::geminiModels()` sends `x-goog-api-key` header, no `key` query; verify an `Http::fake` test asserts the request URL has no `key` parameter and the header is present.
- [x] 2.5 Add `WebFetcher::redactUrl()` (userinfo stripped, sensitive query values masked) and apply to every log line and error string that echoes a URL; verify a test fetching `https://tok@host/?api_key=x` asserts neither appears in the log or the error.
- [x] 2.6 `RawExchangeRecorder` logs exception class and code only on insert failure; verify a test forcing a `QueryException` asserts the request body is absent from the log.
- [x] 2.7 Update README "Turn events" wording for `error.message` and the `.d.ts` comment; run `npm run typecheck`.

## 3. HostGate and WebFetcher hardening

- [x] 3.1 `HostGate::refuse()` refuses URLs with userinfo before any other check; verify `HostGateTest` covers `user@host` and `user:pass@host`.
- [x] 3.2 `addressesFor()` returns `[]` for unresolvable hosts (and resolves `localhost` via DNS), `isPrivateHost()` treats `[]` as private, `validatedAddressFor()` returns null for it, `WebFetcher::addressPinFor()` emits no resolve entry for null; verify a test with `allow_private_hosts: true` and a non-resolving host asserts no `CURLOPT_RESOLVE` entry and the existing "unresolvable is refused" test still passes.
- [x] 3.3 Add a CIDR deny list (`100.64.0.0/10`, `192.0.0.0/24`, `198.18.0.0/15`, `224.0.0.0/4`, `64:ff9b::/96`, `2002::/16`, `fec0::/10`) with embedded-IPv4 extraction for the v6 ranges; verify `HostGateTest` asserts `100.100.100.200`, `224.0.0.1`, `64:ff9b::7f00:1`, and `2002:7f00:1::` are refused and `8.8.8.8` is not.
- [x] 3.4 Refuse `allow_private_hosts: true` when `ToolContext::conversation` is null; verify `HttpRequestToolTest` and `FetchWebPageToolTest` each have an MCP-transport case that asserts refusal and no request, plus a public-host case that still proceeds.
- [x] 3.5 Extend `CREDENTIAL_HEADERS` with the API-key family and add a cross-host benign allow-list applied in `sendFollowingRedirects()` when the hop host differs; verify tests assert `X-Api-Key` is stripped on an unrestricted system and a custom header does not follow a cross-host redirect while `Accept` does.
- [x] 3.6 `credentialsFor()` attaches configured credentials only over `https` or to a loopback address; verify tests for `http://api.example.com` (none), `https://api.example.com` (attached), and `http://127.0.0.1` (attached).
- [x] 3.7 `HttpRequestTool::parseHeaderLines()` validates header names as RFC 7230 tokens and values as printable, reporting rejects in `stripped_headers`; `WebFetcher::send()` also catches `InvalidArgumentException`; verify a test with `Content Type: x` asserts the request proceeds and the header is reported stripped.
- [x] 3.8 Exempt the GET produced by a 301/302/303 rewrite from the `allowed_methods` check; verify a test with `POST`-only policy and a `302` asserts the destination is requested with GET.
- [x] 3.9 `WebFetcher::request()` returns the decoded body on non-2xx (subject to caps); `fetchPage()` unchanged; verify `HttpRequestToolTest` asserts a `422` JSON body reaches `content` alongside `status`.
- [x] 3.10 Enforce `max_body_length` during download via a Guzzle `progress` callback that aborts past the cap, treated as truncation; drop `br` from `Accept-Encoding` when cURL lacks brotli; verify a test streaming a body larger than the cap asserts the result is truncated at the cap and `memory_get_peak_usage()` stays below cap × 4.
- [x] 3.11 Update the maintainer notes on the web-tool layers for the new gate behaviours and the body-cap degradation under a non-cURL handler; verify by reading the section.

## 4. Memory scoping

- [x] 4.1 `AiMemoryService::processCompletedConversation()` returns early with no identity; `applyMemoryOperations()` throws when asked to run unscoped; verify tests assert an identity-less conversation makes no agent call and modifies no rows.
- [x] 4.2 `AiConversationObserver` skips dispatch when the conversation has no identity or has `ai_operator_id`; verify `Queue::fake` tests for both cases and the existing dispatch-once test.
- [x] 4.3 Memories written for a user store `visitor_email = null`; `AiFeatureMemory` visitor scope and `ScanMemoriesTool` add `whereNull('user_id')`; verify tests assert a visitor claiming a user's email reads none of the user's memories via prompt injection or `scan-memories`.
- [x] 4.4 `SystemPromptBuilder::memories()` takes `userId`/`visitorEmail` from the service (no `auth()` fallback); the service passes `$user?->getKey()` and the sanitised visitor email; verify tests assert visitor memories are injected on `startConversation()` and that an authenticated-as-other-user session does not alter whose memories are injected.
- [x] 4.5 `AiMemoryManager::createRules()` requires exactly one of `user_id`/`visitor_email`; verify `AiMemoryManagerTest` covers neither, both, and each alone.
- [x] 4.6 `ScanMemoriesTool` tolerates non-scalar `topics` entries; verify a test passing a nested array asserts no exception and the entry is ignored.
- [x] 4.7 README Memory System section states visitor email is unverified and recommends authentication where confidentiality matters; verify by reading.

## 5. Remote MCP

- [x] 5.1 Move the catalog write inside `RemoteToolCatalogSync::sync()`'s try/catch so a `QueryException` is recorded as `last_sync_error`; verify a test forcing the write to fail asserts no throw, the error recorded, and the prior catalog intact.
- [x] 5.2 Delete rows no longer advertised before the upsert loop and dispatch `RemoteMcpToolDefinitionChanged` when an exposed name changes owner; verify a `FakeMcpServer` test with `a.b` and `a/b` then dropping `a.b` asserts success and the event.
- [x] 5.3 Bound `tools()` listing by `remote_mcp.max_tools` (default 500) and page count; verify a test whose fake returns endless cursors asserts the sync stops with a recorded error and unchanged catalog.
- [x] 5.4 Length-check `title` (truncate 255) and `remote_name` (unrepresentable if > 255) before writing; verify a test with a 300-char remote name.
- [x] 5.5 `RemoteToolSchema` budget check (size, `$ref` count, depth) before `SchemaNormalizer`, stored as the unrepresentable reason; verify a test with a 6-level `$ref` fan-out schema completes in < 1 s and marks the tool unrepresentable.
- [x] 5.6 Cache converted properties on `RemoteToolHandler` so `toLaravelAiTools()` and `RemoteBridgedTool::schema()` convert once; verify a test spying on `RemoteToolSchema` asserts one conversion per registry build.
- [x] 5.7 `RemoteToolHandler::toArray()` caps result bytes at `remote_mcp.max_result_bytes` (default 100 000) with a truncation note and treats `structuredContent === []` as absent; verify tests for an oversized text result and for `{"content":[text],"structuredContent":{}}`.
- [x] 5.8 `RemoteServerSession` trips the breaker on a `JsonRpcException` raised before the session is connected; verify a `FakeMcpServer` test rejecting `initialize` asserts the second call makes no request and the model sees "unavailable".
- [x] 5.9 `AiMcpServerManager::update()` keeps stored `auth` when it arrives null, `''`, or JSON `null`; verify `AiMcpServerManagerTest` covers all three and the next sync still sends the bearer header.
- [x] 5.10 `registerMcpServers()` defaults middleware to `['auth:sanctum']`; verify a test that unsets `code-talker.mcp.web.middleware` asserts the route carries the auth middleware.
- [x] 5.11 Bind `ToolContext` contextually for discovered tools so a constructor parameter not named `$context` still receives the conversation's context; verify `ChatBotToolRegistryTest` adds a fixture tool with `ToolContext $toolContext` and asserts its conversation id.
- [x] 5.12 Document in README that the remote MCP timeout bounds each request and a cold call makes several; add `remote_mcp.max_tools` and `max_result_bytes` to the config file and README; verify by reading.

## 6. Conversation turn correctness

- [x] 6.1 `continueConversation()` re-activates a `Completed` conversation and yields a terminal `error` (`persona_unavailable` / `system_unavailable`) before the try block when the persona is inactive or the system is null; verify tests for each: completed → active after turn; inactive persona → error event, no agent call, `[DONE]` absent; soft-deleted system → error event, no exception.
- [x] 6.2 `CodeTalkerConversationStore::toMessages()` drops tool calls without a matching result, falling back to text when none remain; verify a test with 2 calls / 1 result asserts the replayed assistant message carries one call.
- [x] 6.3 `ConversationTurnRunner` appends only the current attempt's text on a `length` re-prompt and appends nothing when it is empty; verify a test with three `length` stops asserts the second re-prompt's appended message holds only attempt-2 text.
- [x] 6.4 `TurnSequence::nextFor()` computes the max numerically over whole-number labels; verify a test with turns 1–10 asserts the next is 11.
- [x] 6.5 `CompleteIdleConversationsCommand` uses `eachById()` and clamps a negative `idle_timeout_minutes` to 0; verify a test with 1 500 idle rows and chunk 1 000 asserts all are completed, and one with `-5` asserts live conversations are untouched.
- [x] 6.6 Add visitor identity sanitisation in `startConversation()` (trim, strip control chars, ≤255, email format) throwing `InvalidArgumentException` naming the field; verify tests for a newline-bearing name (single line stored and in prompt) and a 300-char name (refused, no row).

## 7. Management validation

- [x] 7.1 `AiSystemManager::updateRules()` accepts `api_key` with keep-on-blank; verify `AiSystemManagerTest` covers rotate, omit, and `''`.
- [x] 7.2 `AiSystemManager::delete()` runs in a transaction, deactivates operators, deletes feature-default claims; verify a test asserts operator `is_active` false, `AiSystemFeatureDefault` rows gone, and `claimedFeatures()` no longer lists them.
- [x] 7.3 `config`/`pricing_profile` validated as JSON objects and `config.provider_options` as an object; verify tests reject `"true"`, `"[1,2]"`, and `{"provider_options":"x"}`.
- [x] 7.4 `base_url` required for `openai-compatible` on create; `temperature` `max:2` in `AiSystemManager` and `AiPersonaManager`; verify tests.
- [x] 7.5 Persona/operator `ai_system_id` rule requires non-deleted, active, chat-role system; verify `AiPersonaManagerTest` and `AiOperatorManagerTest` reject soft-deleted, inactive, and audio systems.
- [x] 7.6 `AiSystemCapabilityService::resolveLmStudioCapabilities()` passes the API key and returns null on transport failure; verify a test with `Http::fake` 401 asserts the key header was sent, and one with a connection failure asserts the save succeeds with capabilities unchanged.

## 8. Stream client

- [x] 8.1 `code-talker-stream.ts` aborts immediately on a pre-aborted signal and removes its abort listener in `finally`; run `npm run typecheck` and verify `FrontendAssetPublishingTest` still passes.

## 9. Verification

- [x] 9.1 Run `composer test` and confirm the full suite passes with the new tests included.
- [x] 9.2 Confirm `openspec validate pre-1-0-security-and-bug-fixes --strict` passes.

## Context

See proposal.md — Why. The constraints that shape the fixes:

- The wire *shape* (`StreamTranslator` events, `SseFrameEncoder` framing) is public API; only `error.message` *text* changes.
- `AiChatBotConversationService`'s five-argument constructor and the `protected` guard methods are pinned by tests; new collaborators are built inside the constructor.
- `RemoteToolHandler::handle()` and `BridgedTool::handle()` run inside laravel/ai's loop with no try/catch — anything that throws ends the turn.
- The registry is built per turn and per admin listing, so nothing added to that path may hit the network or do heavy work.
- `HostGate::addressesFor()` fails closed on purpose; the fix must preserve "unresolvable ⇒ refused" while removing "unresolvable ⇒ pinned to loopback".
- Tests run on SQLite; MySQL/Postgres behaviour (collations, sequences) has to be pinned by pretend-mode SQL or reasoning, as `credentials` already is.

## Goals / Non-Goals

**Goals:**
- Every finding in the proposal fixed with a pinning test, in one change, so 1.0.0 ships without known holes.
- No new configuration where a safe fixed behaviour suffices; new config only where operators legitimately differ (remote result cap, tool-count bound).
- Error paths that previously threw out of a generator or a tool now end with a structured error.

**Non-Goals:**
- Making the model-declared `request_policy` a boundary. It stays a guardrail; egress control remains the host's job.
- Verified visitor identity. We separate user-scoped from visitor-scoped memories and document the trust level; a verification hook is a later feature.
- Reworking `ai_llm_messages.turn_number` into an integer column. We fix the ordering query; a type migration is not worth the churn now.
- A per-call (as opposed to per-request) remote MCP timeout budget. Documented instead.
- CHANGELOG — written in the release commit per `cutting-a-release`.

## Decisions

### D1. Error text: fixed message per reason, full exception to logs

`AiPersonaConversationService` yields `message: $exception->getMessage()`. Replace with a small `TurnErrorMessage::for(reason)` map (`provider_error`, `max_stream_duration` — the existing wire reason, kept to avoid a contract change — `persona_unavailable`, `system_unavailable`). The exception is already recorded by `TurnRecorder::recordFailure()`; additionally `Log::error` with `['exception' => $e]`. `continueConversation()` returns the failing exception as the generator's return value so `RunConversationTurnJob` can keep it in `AiTurnRun.error_message`. `AiModelReadinessService::statusForSystem()` does the same with a fixed "Provider is unavailable." and `Log::warning`. `SearchWebTool::searchEngine()` returns `EngineResults::threw($engine, 'transport failure')`.

*Alternative:* allow-list laravel/ai exception classes whose messages are safe. Rejected — Guzzle's `for <uri>` suffix is attached at a layer below any class we could trust, and a list rots.

### D2. Secrets never in URLs; redaction before logging

`ProviderModelsClient::geminiModels()` sends `x-goog-api-key` (the header laravel/ai's own gateway uses). `WebFetcher` gets one `redactUrl()` helper (strip userinfo; mask the value of any query parameter whose name contains `key`, `token`, `secret`, `signature`, `credential`, `password`, `passwd`, or `session`, or is `sig`, `code`, `auth`, or `pwd` — fragments rather than a fixed list, so vendor spellings such as `X-Amz-Signature` are covered) applied to every log line and every error string that echoes a URL. `RawExchangeRecorder` logs `get_class($e)` and `$e->getCode()` only — `QueryException::getMessage()` interpolates bindings.

### D3. `uuid` migration with backfill

New migration adds `ai_conversations.uuid` `string(36)->nullable()->unique()`, then backfills each null row with `Str::uuid()` one at a time (not a mass update — each needs a distinct value). `generateChatHash()` gains a guard: if `uuid` is blank, assign one before hashing, so a row a host created before the column existed cannot produce `md5('')` and collide on `chat_hash`. The two test suites that add the column by hand lose that code. README's migrating table gets a row.

### D4. Memory scoping: identity required, user wins

- `ProcessAiMemoryJob` / `AiMemoryService::processCompletedConversation()` returns early when both identities are null. `AiConversationObserver` also skips dispatch for those (saves the queue job) and for `ai_operator_id !== null`.
- `applyMemoryOperations()` refuses to build an unscoped query — if a caller ever reaches it with neither identity it throws, which fails the job loudly instead of touching the whole feature.
- Rows written for a user get `visitor_email = null`. The `visitor_email` scope on `AiFeatureMemory` and `ScanMemoriesTool` additionally adds `whereNull('user_id')` so legacy dual-scoped rows stop matching visitor queries (no data migration needed; a backfill nulls `visitor_email` where `user_id` is set, in the same migration as D3 to keep the count at one).
- `SystemPromptBuilder::memories()` receives `?int $userId, ?string $visitorEmail` from the service (`$user?->getKey()`, sanitised email) and loses the `auth()` branch. For the `openConversation`/presenter path the service passes the authenticated user explicitly — same result as today, but the decision lives with the caller.
- `AiMemoryManager::createRules()` adds `user_id` / `visitor_email` with `required_without` each way and `prohibits` the other.

*Alternative:* match visitor queries against rows with the same email regardless of `user_id`. Rejected — it would let a typed email read a user's memories.

### D5. Completed conversation: re-activate, don't refuse

`continueConversation()` sets `status = Active` when it finds `Completed`. Refusing would break any host UI that lets a user reopen an old thread, and re-activating is what the idle sweep expects — the conversation gets swept and analysed again (`analyzeConversation()` is incremental against existing memories). Persona inactive or system null ⇒ a terminal `error` event (`persona_unavailable` / `system_unavailable`) yielded *before* the try block, so `recordFailure()` is never asked to read a null system.

### D6. Replay drops unmatched tool calls

`CodeTalkerConversationStore::toMessages()` keeps only tool calls whose id has a result; if none remain, falls back to the text (the existing M=0 path). This fixes the stored history read-side; `TurnRecorder` still persists what happened, so the record is faithful and the replay is valid.

### D7. HostGate changes

- `refuse()` checks `parse_url` for `user`/`pass` first and returns a fixed message.
- `addressesFor()` returns `[]` for unresolvable; `isPrivateHost()` treats `[]` as private; `validatedAddressFor()` returns `null` for `[]`; `WebFetcher::addressPinFor()` emits nothing for `null`. `localhost` goes through `dns_get_record`.
- Add `PRIVATE_CIDRS` (v4: `100.64.0.0/10`, `192.0.0.0/24`, `198.18.0.0/15`, `224.0.0.0/4`; v6: `64:ff9b::/96`, `2002::/16`, `fec0::/10`) checked with a small CIDR matcher after `filter_var`. `2002::/16` and `64:ff9b::/96` are denied as whole ranges, so extracting their embedded IPv4 could never change the result; IPv4-mapped and -compatible forms are already refused by `filter_var`.
- `ToolContext::conversation === null && policy.allowPrivateHosts` ⇒ refuse in `HostGate::refuse()` via a new `$fromExternalCaller` flag the tools pass from `ToolContext`.

### D8. WebFetcher header and credential rules

- `CREDENTIAL_HEADERS` extended with the API-key family. The gate (`allowsCredentialHeaders()`) and the same-host rule already apply to this set; only the list grows.
- New `CROSS_HOST_SAFE_HEADERS` allow-list; `sendFollowingRedirects()` sends `$safeHeaders` and the caller's permitted credential headers only while the hop's origin (scheme, host, effective port) matches the URL the caller named, else intersects with the allow-list. Comparing host alone would let a same-host https→http redirect carry them.
- `credentialsFor()` returns `[]` unless scheme is `https` or the validated address is loopback.
- `HttpRequestTool::parseHeaderLines()` validates name against `/^[!#$%&'*+\-.^_`|~0-9A-Za-z]+$/` and value against `/^[\x20\x09\x21-\x7E\x80-\xFF]*$/`; rejects go to `stripped_headers`. `WebFetcher::send()` also catches `\InvalidArgumentException` as belt-and-braces.
- Redirect-induced GET passes `methodFromRedirect: true` to the hop gate, which skips `permits()`.
- `request()` returns a failure *with* the decoded body for non-2xx; `fetchPage()` is unchanged.

### D9. Body cap enforced during download

Pass a `CappedBodySink` (a PHP stream wrapper) as Guzzle's `sink`. It receives decoded bytes and makes a short write at the cap, so cURL aborts the transfer (error 23). `send()` catches that only when the sink reports it hit the cap and an `on_headers` response was captured, and rebuilds the response from those headers and the truncated body. It must be a real resource, not a PSR-7 stream, because `Http::fake()` `fwrite()`s the stub body into the sink. Keep `Accept-Encoding` but drop `br` unless `curl_version()['features'] & CURL_VERSION_BROTLI`.

*Alternative:* Guzzle's `progress` option. Rejected after measuring: it counts wire bytes, so a 5 MB gzip body reported about 5 KB downloaded and then decoded in full.

*Alternative:* `stream => true` with a manual read loop. Works, but changes the response type `WebFetcher` passes around.

### D10. Remote MCP sync robustness

- Whole write inside the existing try/catch; `DB::transaction` failure records `last_sync_error` like a transport failure.
- Inside the transaction: compute `$seenRemote` first, `delete()` rows not in it, null the exposed names kept rows are giving up (so two kept rows can trade names), *then* upsert — so a reassigned exposed name never collides. Track previous owner per exposed name; if the owner `remote_name` changed, dispatch `RemoteMcpToolDefinitionChanged` for the new row.
- `BoundedToolListing` (extends laravel/mcp's `ListTools`) with `remote_mcp.max_tools` (default 500) as both the tool and page bound; exceeding either ⇒ `last_sync_error`, catalog untouched. It reaches the client's protected `protocol` through a bound closure, since `Client::tools()` cannot bound pages.
- `RemoteToolSchema::assertWithinBudget()` before `SchemaNormalizer`: refuse if `strlen(json) > 64 KB`, `$ref` count > 100, `json_decode` depth > 32, or more than 20 000 nodes once `$ref`s are expanded — the last catches a small fan-out schema the first three miss. The count follows the normalizer's per-path cycle guard and memoizes a reference only when its expansion met no cycle, since a size cut short by a cycle depends on the path; reason stored. Cache converted properties on `RemoteToolHandler` so `toLaravelAiTools()` and `schema()` convert once.
- `title`/`remote_name` truncated to 255 (title) / rejected as unrepresentable (remote name) before write.
- `RemoteToolHandler::toArray()` caps text and JSON-encoded structured content at `remote_mcp.max_result_bytes` (default 100 000) with a truncation note; `structuredContent === []` treated as absent.
- `RemoteServerSession`: a `JsonRpcException` thrown before `connected()` trips the breaker.
- `AiMcpServerManager::update()`: unset `auth` when null/`''`/JSON `null`, before validation (a JSON `null` would otherwise fail the object rule).
- `registerMcpServers()` uses `['auth:sanctum']` when the key is missing or null; an explicit `[]` is honoured.
- `DiscoversAiToolHandlers`: add a `makeWith` override for every constructor parameter typed `ToolContext`, found by reflection, whatever its name. A contextual binding was rejected: it has no removal API and would outlive the build, so a later resolution of the same class on the MCP transport could receive this conversation's context. Test asserts a tool with `$toolContext` receives the conversation.

### D11. Management validation

- `updateRules()` adds `api_key` nullable string; `update()` applies the keep-on-blank rule already used for `credentials`.
- `delete()` wraps in `DB::transaction`, deactivates `operators()`, deletes `featureDefaults()`. Return type stays `int` (persona count) to keep the public signature; operator count is logged.
- `config`/`pricing_profile` use `isJsonObject()`; `config.provider_options` validated after decode as `array` with string keys.
- `base_url` `required_if:provider,openai-compatible` on create; on update the stored value satisfies it.
- `temperature` `max:2`.
- Persona/operator `ai_system_id`: `Rule::exists('ai_systems','id')->whereNull('deleted_at')->where('is_active', true)->where('role', 'chat')`.
- `AiSystemCapabilityService::resolveLmStudioCapabilities()` passes `$apiKey`; transport failure ⇒ return `null` (caller keeps stored capabilities) rather than throw.

### D12. Small correctness fixes

- `TurnSequence`: select `turn_number` where it has no `.`, cast to int in PHP, take max — portable across drivers without `CAST` dialects.
- `CompleteIdleConversationsCommand`: `eachById()`; `max(0, (int) config(...))`.
- `ConversationTurnRunner`: track `$attemptText` per iteration; skip appending when empty.
- `startConversation()`: `VisitorIdentity::sanitise()` — trim, strip `\p{C}`, enforce ≤255, email `filter_var` — throws `InvalidArgumentException` with the field name.
- `code-talker-stream.ts`: `if (signal?.aborted) controller.abort()`; `finally { signal?.removeEventListener(...) }`.
- Prompt seed migration: insert without ids, then update `AiSystemPromptIds` consumers to look up by a stable `slug`/title; or keep ids and `SELECT setval(...)` when `DB::getDriverName() === 'pgsql'`. Choose the latter: it keeps `AiSystemPromptIds` constants valid and is one line.

## Risks / Trade-offs

- [Generic error text hides useful detail from developers] → full exception still logged and stored on `AiInteractionLog`/`AiTurnRun`; README points there.
- [TLS-only credentials break a host using an `http://` internal API with credentials] → loopback exempt; documented as breaking; such a host can terminate TLS or proxy.
- [Broader credential-header class blocks a legitimate `X-Api-Key` on an unrestricted system] → that is the intended outcome; operators attach keys via `web_tool_policy.credentials`, which still works.
- [Under a non-cURL Guzzle handler the sink still caps what is kept, but whether the transfer stops early depends on the handler honouring a short write] → same degradation class as the existing IP-pinning no-op; noted in the maintainer notes.
- [Re-activating a completed conversation triggers a second extraction later] → incremental by design; cost is one analysis per completion, not per turn.
- [Deleting rows before upserting in sync changes the order side effects fire in] → all inside one transaction; a failure rolls back both.
- [Schema budget could reject a legitimate large schema] → stored with a reason the admin can read; thresholds are generous (64 KB / 100 refs).
- [`uuid` backfill on a huge table is row-by-row] → runs once; chunked by id.

## Open Questions

None that affect the specs or task breakdown. Thresholds (`max_tools`, `max_result_bytes`, schema budget) are configurable and can be tuned after release.

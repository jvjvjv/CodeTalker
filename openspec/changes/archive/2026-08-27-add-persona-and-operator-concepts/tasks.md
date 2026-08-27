## 1. Rename migrations (AiChatBot → AiPersona)

- [x] 1.1 Migration: rename `ai_chat_bots` table → `ai_personas`
- [x] 1.2 Migration: rename `ai_conversations.ai_chat_bot_id` → `ai_persona_id` (drop/re-add the FK against the renamed table if `renameColumn` doesn't carry the constraint)
- [x] 1.3 Migration: rename `ai_interaction_logs.ai_chat_bot_id` → `ai_persona_id` (same FK caveat)
- [x] 1.4 Data migration: rewrite `ai_system_feature_defaults.feature` rows matching `chat-bot:%` to the same slug suffix under `persona:`

## 2. New migrations (AiOperator)

- [x] 2.1 Migration: create `ai_operators` table — `id`, `ai_system_id` (FK), `name`, `slug` (unique), `description` (nullable), `prompt_template`, `allowed_tools` (json, nullable), `is_active`, timestamps, soft deletes
- [x] 2.2 Migration: add `ai_conversations.ai_operator_id` — nullable FK to `ai_operators`, `nullOnDelete()`

## 3. Rename: models and factories

- [x] 3.1 Rename `AiChatBot` model → `AiPersona` (class, `$table` if not inferred, all references)
- [x] 3.2 Rename `AiChatBot::featureKey()`'s `'chat-bot:'` prefix → `'persona:'`
- [x] 3.3 Rename the `ai_chat_bot_id` fillable/relation on `AiConversation` and `AiInteractionLog` → `ai_persona_id`; add `belongsTo` for the renamed model
- [x] 3.4 Rename the host-app factory class hosts are expected to provide (`Database\Factories\AiChatBotFactory` → `AiPersonaFactory`) — noted in README/CHANGELOG as a host-side action (see task 12)
- [x] 3.5 Update `AiSystem`'s references to `AiChatBot` (relations `chatBots()` → `personas()`, added `operators()`)

## 4. New model: AiOperator

- [x] 4.1 Create `AiOperator` model — fillable per migration, `belongsTo(AiSystem::class)`, `featureKey(): string` returning `'operator:' . $this->slug`
- [x] 4.2 Add `AiConversation::belongsTo(AiOperator::class)` (`ai_operator_id`)

## 5. Rename: services

- [x] 5.1 Rename `AiChatBotConversationService` → `AiPersonaConversationService`, preserving the pinned five-positional-argument constructor (`AgentFactory`, `AiMemoryService`, `ConversationUsageService`, `RawExchangeContext`, `AiSystemProviderConfigurator`)
- [x] 5.2 Rename `Services/Management/AiChatBotManager` → `AiPersonaManager`; update its `rules()`/`createRules()`/`updateRules()` and any `AiChatBot` type hints
- [x] 5.3 Update `SystemPromptBuilder`'s `AiChatBot $bot` parameter → `AiPersona $persona`. Also renamed the prompt-template placeholders (`{{bot_name}}`/`{{bot_slug}}`/`{{bot_description}}` → `{{persona_name}}`/`{{persona_slug}}`/`{{persona_description}}`) for consistency — not itemized separately in this plan, but clearly within the rename's scope since it's the same public template contract.
- [x] 5.4 Update `CodeTalkerConversationStore`'s docblock/error-message references from `AiChatBot`/`AiChatBotConversationService` to the renamed classes
- [x] 5.5 Update `ChatBotPresenter`/`ChatBotStatusResolver` internals that type-hint `AiChatBot` (class names/namespace stay `ChatBot*` per design.md — only the parameter/property types change). Also updated `AiConversationManager` (not separately itemized) — its `ai_chat_bot`/`aiChatBot` references, filter key, and relation list.
- [x] 5.6 Update `AiModelReadinessService` (also renamed `statusForChatBot()`/`warmUpChatBot()` → `statusForPersona()`/`warmUpPersona()`, unused elsewhere) and `ReadProviderExchangeCommand`'s `AiChatBot` references. Also updated `Support/ToolContext` and `Support/WebScraperUserAgent` (not separately itemized) — both read `$conversation->aiChatBot`.

## 6. New: prompt interpolation

- [x] 6.1 Add a dotted-placeholder interpolator (`Services/Operator/OperatorPromptInterpolator`): replaces `{{a.b.c}}` tokens in a template string via `Arr::get()` against a context array
- [x] 6.2 Throw a clear exception when a placeholder resolves to `null` (key genuinely missing from context), naming the placeholder
- [x] 6.3 Unit tests: nested placeholder resolution, missing-key failure, a template with no placeholders passes through unchanged (plus a non-scalar value JSON-encodes)

## 7. New: operator dispatch and execution

- [x] 7.1 `RunAiOperatorJob implements ShouldQueue` — constructor `(AiOperator $operator, array $context = [])`, `tries`/`backoff` matching `ProcessAiMemoryJob`'s pattern
- [x] 7.2 New runner service `Services/Operator/AiOperatorRunner` — creates the backing `AiConversation` (`feature` = `operator:{slug}`, `ai_operator_id` set, `ai_persona_id` null), interpolates the prompt, resolves the agent via `AgentFactory::forSystem($operator->aiSystem)`, calls the agent's non-streaming `prompt()` (not `stream()`)
- [x] 7.3 Record exactly one `AiLlmMessage` request/response pair for the run, wrapped in `RawExchangeContext::push()`/`pop()` like `ConversationTurnRunner` does
- [x] 7.4 Only `FinishReason::Stop` is treated as success; anything else (max-tokens/`Length`, `ToolCalls` cut off at the step cap, `ContentFilter`, `Error`, etc.) fails the job. The conversation's status is set to `Pass` (not `Completed`) on that path so it's distinguishable from a clean run.
- [x] 7.5 `RunAiOperatorJob::handle()` calls the runner and lets a thrown failure propagate to the queue's normal failure handling

## 8. New: AiOperatorManager

- [x] 8.1 `Services/Management/AiOperatorManager` — create/update/delete/list, static `rules()`/`createRules()`/`updateRules()`, mirroring `AiPersonaManager`'s shape
- [x] 8.2 List includes each operator's run count and usage rollup (via its `AiConversation` rows), matching what persona listing already provides

## 9. Config and frontend

- [x] 9.1 N/A — no `inertia.components.chat_bot` config key exists. Inertia support (config block, components, `bot` prop) was removed entirely in 0.11.0; confirmed by grep and `CHANGELOG.md`. This task was based on stale content in the pre-existing `frontend-integration-contract` spec (unrelated drift, not touched by this change).
- [x] 9.2 N/A — `resources/js/types/code-talker.d.ts` has no `bot`/`ChatBot`/`ChatBotsIndex` page-prop types; it only declares the SSE turn-event union, which has no persona-related content to rename.
- [x] 9.3 Run `npm run typecheck` — clean (no changes were needed, per 9.1/9.2 above)

## 10. Rename: tests

- [x] 10.1 Rename `AiChatBotConversationServiceTest` → `AiPersonaConversationServiceTest`; renamed `makeBot()` → `makePersona()`, `$bot` → `$persona` throughout; kept the pinned constructor-signature test intact under the new class name. Also fixed a real bug this surfaced: an already-shipped migration (`2026_04_12_000004_add_access_path_to_ai_chat_bots_table.php`) referenced `AiChatBot::ACCESS_PATH_CHAT` as a class constant, which broke a fresh install once the model was deleted — switched it to the literal `'chat'` (same value, no behavior change).
- [x] 10.2 Updated every other test referencing `AiChatBot`/`makeBot()`/`AiChatBotManager`: `AiSystemManagerTest`, `ChatTurnLibraryTest`, `CodeTalkerConversationStoreTest`, `CompleteIdleConversationsCommandTest`, `FetchWebPageToolTest`, `HttpRequestToolTest`, `ManagementServicesTest`, `PackageSmokeTest`, `RawExchangeChatIntegrationTest`, `ReadProviderExchangeCommandTest`. `ChatBotToolRegistryTest` needed no changes — it never referenced `AiChatBot`.
- [x] 10.3 N/A — `tests/Fixtures/Tools/PageReloadingTestTool.php` and no other shared test helper reference `AiChatBot`; confirmed via grep across `tests/`.

## 11. New: operator tests

- [x] 11.1 `AiOperatorRunnerTest`: a dispatched run creates an `AiConversation` with `ai_operator_id` set, records one `AiLlmMessage`, interpolates context into the prompt
- [x] 11.2 A run with an unresolved placeholder fails before any provider call is made (asserted via `CodeTalkerAgent::assertNeverPrompted()` and zero `AiLlmMessage` rows)
- [x] 11.3 A run with a non-`Stop` finish reason (e.g. `Length`/max-tokens) fails the job — no silent continuation
- [x] 11.4 `AiOperatorManagerTest`: create/update/delete/list, `rules()` validation
- [x] 11.5 Usage rollup test — and this surfaced a real gap: `AiOperatorRunner` only wrote `AiLlmMessage` rows, but `ConversationUsageService::buildUsageSummary()` actually reads `AiInteractionLog` (keyed by `ai_conversation_id`, not persona-specific). Without an `AiInteractionLog` row, the "usage tracking for free" claim in the proposal/design was false for operators. Fixed by having the runner log one `AiInteractionLog` (status `Success`, since real tokens are billed regardless of the run's outcome) and call `ConversationUsageService::syncConversation()`, exactly mirroring `TurnRecorder::recordCompletedTurn()`. Test confirms the sync populates `usage_synced_at` with zero operator-specific code in `ConversationUsageService`.

## 12. Documentation

- [x] 12.1 README: renamed the "Chat Bots" section → "Personas" (`AiChatBot`/`bot` references, placeholders); no Inertia prop/config key existed to rename (see note on task 9 — pre-existing spec drift, not real)
- [x] 12.2 README: added a new "Operators" section — configuring `AiOperator`, dispatching `RunAiOperatorJob`, the dotted-placeholder template syntax, audit/cost tracking, and that dispatch/scheduling is entirely host-owned
- [x] 12.3 README: updated the Management Services table to list `AiOperatorManager` alongside the renamed `AiPersonaManager`
- [x] 12.4 CHANGELOG: added a `0.14.0` entry — Breaking Changes (rename, FK renames, placeholder renames, feature-key prefix, host factory rename) and New Features (`AiOperator`, `RunAiOperatorJob`, dotted-placeholder templating, `AiOperatorManager`)

## 13. Verify

- [x] 13.1 `composer test` — 284 passed
- [x] 13.2 `npm run typecheck` — clean
- [x] 13.3 Grepped the repo for remaining `AiChatBot`/`chat-bot:`/`ai_chat_bot` references. Zero left in `src/`, `tests/`, `resources/js/`, `README.md`, `config/`. Also caught and fixed test-fixture literal `'chat-bot:...'` feature-key strings (not class references, but inconsistent with the real `persona:` prefix now) and `ReadProviderExchangeCommand`'s interactive prompt text ("Select a chat bot" → "Select a persona"), neither separately itemized. Two intentional survivors remain: `Services/ChatBot/*` namespace (design.md: stays, it's turn-execution machinery, not persona-specific) and the two rename migrations themselves (`database/migrations/2026_08_27_*`), whose filenames/table-literal-strings necessarily reference the old names since that's what they're migrating *from*.

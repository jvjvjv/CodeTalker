## 1. Configuration

- [x] 1.1 Add a `feature_keys` top-level key to `config/code-talker.php`, defaulting to `[]` (unrestricted). It must be top-level, not nested under an existing block — `mergeConfigFrom` is a shallow merge, so a host's published copy would replace a nested block wholesale and drop the new subkey
- [x] 1.2 Read it with an inline default everywhere, matching the package convention

## 2. AiSystemManager

- [x] 2.1 Create `Services/Management/AiSystemManager` with `AiSystemCapabilityService` and `ProviderModelsClient` injected
- [x] 2.2 Port `createRules()` / `updateRules()` from the two form requests, replacing `in:targeted-resume,cover-letter` with the configured feature keys
- [x] 2.3 `create(array $data): AiSystem` — the pipeline in its existing order: split feature defaults → resolve custom prompt → decode JSON fields → normalize → hydrate → persist → sync defaults. **Order is load-bearing**
- [x] 2.4 `update(AiSystem, array $data): AiSystem` — same pipeline, plus re-injecting the stored provider/model for hydration and discarding them before write, and the blank-`base_url` fallback
- [x] 2.5 `delete(AiSystem): int` — deactivate linked bots, return the count, soft-delete
- [x] 2.6 `duplicate(AiSystem, bool $copyFeatureDefaults = false): AiSystem` — resolve the feature-default question explicitly rather than porting the silent drop
- [x] 2.7 `syncFeatureDefaults(AiSystem, array $features): void` — **wrapped in a transaction**, which the controller version lacked
- [x] 2.8 `claimedFeatures(?AiSystem $excluding = null): array`
- [x] 2.9 `availableModels(AiProvider, ?string $apiKey, ?string $baseUrl): array` — the `requiresApiKey()` precondition throws before any network call; preserve the `{id, name, loaded, max_context_length, capabilities{reasoning,vision,tools}}` shape sorted by name. Let provider exceptions propagate; the host decides the HTTP response

## 3. AiSystemPromptManager

- [x] 3.1 Create the manager with `rules()`, `create()`, `update()`
- [x] 3.2 `delete(AiSystemPrompt): int` — clear `system_prompt_id` on referencing systems, return the count, delete, **all in one transaction**

## 4. AiChatBotManager

- [x] 4.1 `createRules()` / `updateRules(?AiChatBot)` — port the reserved-root-slug closure and the self-ignoring unique rule; the update variant takes the bot explicitly rather than reading route-model binding
- [x] 4.2 `create()`, `update()`, `delete()`
- [x] 4.3 `listWithUsage(?int $aiSystemId = null): array` — the three `withSum` rollups and the null-usage rule; drop the `'_id' => $bot->aiSystemId` dead key
- [x] 4.4 `availableSystems(): array` — active systems shaped for a bot form
- [x] 4.5 `availableTools(?int $aiSystemId, bool $includeAll, string|int|null $userId = null): array` — the throwaway in-memory `AiConversation`, the `include_all` override, the `{name, description}` projection; drop the unused `AiMemoryService` parameter

## 5. AiConversationManager

- [x] 5.1 `paginate(array $filters, int $perPage = 50)` — five filters plus the six-way search, message counts excluding `system`, and the existing row shape
- [x] 5.2 Preserve the `method_exists(AiConversation::class, 'targetedResume')` optional-relation detection — it is the escape hatch for host-extended conversation models
- [x] 5.3 `detail(AiConversation): array` — messages chronological, memories by descending confidence
- [x] 5.4 `delete()`, `features()`, `queueUsageBackfill(bool $all = false, int $chunk = 200)`

## 6. AiMemoryManager

- [x] 6.1 `createRules()` / `updateRules()` — note `feature` is absent from update, making it immutable; keep that and document it
- [x] 6.2 `paginate(array $filters, int $perPage = 50)` — the active/confidence/reinforced ordering
- [x] 6.3 `create()`, `update()`, `delete()`, `features()`, `rebuild(string $feature)`

## 7. Tests

Written before the controllers are deleted, since nothing currently covers them.

- [x] 7.1 `AiSystemManagerTest` — pipeline ordering, custom-prompt creation, JSON decoding, provider/model immutability, base-URL fallback, delete cascade count, duplicate
- [x] 7.2 Feature-default tests — steal semantics, atomicity, `claimedFeatures()` with and without exclusion
- [x] 7.3–7.6 Prompt, chat-bot, conversation, and memory manager coverage — landed together in `ManagementServicesTest` rather than four files, since they share the schema setup (the `users` table and the `uuid` column Testbench does not create)
- [x] 7.7 Validation tests — the provider-conditional API key rule, the configurable feature keys

## 8. Removal

- [x] 8.1 Delete `src/Http/Controllers/Admin/` (six controllers)
- [x] 8.2 Delete `src/Http/Requests/Admin/` and the four `AiSystem`/`AiSystemPrompt` form requests
- [x] 8.3 Delete `routes/codetalker-admin.php`
- [x] 8.4 Remove admin route loading, the `code-talker-admin-routes` publish tag, and the admin entry in the `code-talker-routes` tag from `CodeTalkerServiceProvider`
- [x] 8.5 ~~Remove `admin_middleware` from config~~ — **kept deliberately.** A host that published the admin route file still loads it, and that file reads this key; removing it would resolve `null` and break their middleware stack. Left in place with a comment explaining why it survives
- [x] 8.6 Confirm `PackageSmokeTest` still passes, updating any admin-route assertion

## 9. Documentation

- [x] 9.1 Replace the README's **Admin Routes** section with a **Management Services** section covering each manager and its operations
- [x] 9.2 Document the new `feature_keys` config key
- [x] 9.3 State plainly that the admin routes are gone and how a host rebuilds them, since this is the breaking part
- [x] 9.4 Update `CLAUDE.md`: remove the admin route description, add `Services/Management/`
- [x] 9.5 Run `composer test` — full suite green

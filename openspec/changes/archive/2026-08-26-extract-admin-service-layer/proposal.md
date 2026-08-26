## Why

Target release: **0.11.0**. First of three staged changes reshaping the package around conversation storage and tooling.

The admin surface is 1,521 lines across six controllers and eight form requests, and it cannot work as shipped. The controllers render **16 distinct Inertia components** — `ai/systems/Index`, `ai/bots/Edit`, `ai/conversations/Show`, and thirteen more — that the package does not ship, does not document, and does not type. Unlike the chat pages, there is no prop table, no TypeScript declaration, and no publish tag. A host can only use them by independently building sixteen components against an undocumented interface.

Test reach is **1%** — a single reference to the 15-line `AiToolsController`. Nothing in `tests/` names any other admin controller, so deleting the whole directory today breaks no test. That is the problem, not the reassurance: no test protects the extraction either.

But this is **not a deletion**. Reading the controllers turns up eighteen pieces of logic that exist nowhere else in the package, several of them load-bearing invariants. Deleting the files without extracting them first would silently destroy them.

## What Changes

A new `Services/Management/` namespace absorbs everything real, and the HTTP layer above it is removed.

**Logic that must move (exists only in controllers today):**

- **The `AiSystem` persistence pipeline** — the ordering in `store()`/`update()` is load-bearing: strip `feature_defaults` → resolve custom prompt → decode JSON fields → normalize → hydrate capabilities → persist → sync defaults.
- **`resolveCustomSystemPrompt()`** — implicit `AiSystemPrompt` creation from free text, with the `mb_substr($name.' Custom Prompt', 0, 64)` title convention silently honoring the request's `max:64`.
- **`decodeJsonFields()`** — `config`, `credentials`, and `pricing_profile` arrive as JSON *strings* and must be decoded before write.
- **`syncFeatureDefaults()`** — the "one system owns a feature globally" invariant: delete own → delete other systems' claims on those features → recreate.
- **The update immutability dance** — `provider` and `model` are omitted from `UpdateAiSystemRequest`, then temporarily re-injected by the controller purely so `AiSystemCapabilityService` can resolve capabilities, then unset before write. A two-file invariant with no test.
- **Two cascades** — `AiSystemController::destroy()` deactivates (does not delete) linked chat bots and reports the count; `AiSystemPromptController::destroy()` nulls `system_prompt_id` on referencing systems and reports the count.
- **`fetchModels()`** — ad-hoc-credential model discovery with the `requiresApiKey()` precondition and the normalized `{id, name, loaded, max_context_length, capabilities}` shape.
- **`mcpTools()` filtering** — the throwaway in-memory `AiConversation` used to satisfy `ChatBotToolRegistry`, and the `include_all` vs system `allowed_tools` precedence rule.
- **Per-bot cost aggregation** — three `withSum` rollups plus the rule that usage is null unless the cost sum is non-null. `ConversationUsageService` has no bulk equivalent.
- **The conversation search engine** — five filters plus a six-way cross-relation `LIKE` search spanning title, visitor fields, related user, related bot, and non-system message bodies.
- **The "exclude `role = system`" rule**, applied to both message counts and message search.
- **Optional-relation detection** — `method_exists(AiConversation::class, 'targetedResume')`, the escape hatch for host-extended conversation models, appearing in four places.
- **Conversation → memory correlation** — `AiFeatureMemory where source_conversation_id`, ordered by confidence.

**Domain validation moves too**, out of form requests and into the services: the provider-conditional API-key rule (currently split across `StoreAiSystemRequest` and `AiSystemCapabilityService::normalizeForPersistence()`), the reserved-root-slug rule, the numeric ceilings (`context_length` ≤ 200,000 but `model_capabilities.max_context_length` ≤ 2,000,000; `temperature` 0–1; `confidence` 1–100), and the memory category vocabulary.

**Removed:** all six admin controllers, all eight form requests, `routes/codetalker-admin.php`, the `code-talker-admin-routes` publish tag, and the admin half of the `code-talker-routes` tag.

**Defects fixed rather than ported:**

- `syncFeatureDefaults()` and the prompt-destroy cascade are multi-write operations with **no transaction**. Both get one.
- `feature_defaults` is validated as `in:targeted-resume,cover-letter` — host-app-specific feature names hardcoded into a redistributable package. Becomes configurable.
- Four public controller methods have **no route at all** (`fetchModels`, `modelStatus`, `modelWarmup`, `apiUpdate`); `fetchModels` is the richest logic in the admin layer and is currently unreachable. As service methods they become callable.
- `duplicate()` silently drops feature defaults — resolve deliberately rather than preserving the ambiguity.
- Dead code: unused `AiMemoryService` parameter in `mcpTools()`, unused `ProcessAiMemoryJob` import in `AiMemoryController`, and `'_id' => $bot->aiSystemId` (a camelCase property read against a snake_case column, so always null).

## Capabilities

### New Capabilities

- `ai-management-services`: programmatic management of systems, prompts, chat bots, conversations, and memories — the operations an admin UI performs, exposed as services a host app can call from its own controllers, commands, or tests.

### Modified Capabilities

`AiSystemCapabilityService` gains a documented contract with its caller. Today it depends on `provider`/`model`/`base_url` being present on the incoming array, which the controller arranges by hand; the extracted service makes that explicit.

## Impact

- **Code**: new `Services/Management/`; removal of `src/Http/Controllers/Admin/`, `src/Http/Requests/Admin/`, the four `AiSystem`/`AiSystemPrompt` form requests, and `routes/codetalker-admin.php`.
- **Line count**: roughly flat, possibly slightly up. **The win is not size** — it is that 1,521 lines of untested, unreachable-without-a-bespoke-UI code becomes a tested service layer any host can drive. Say so plainly in the release notes so the change is not mistaken for a slimming exercise.
- **Testing**: this is the risk. No existing test covers any of it, so the extraction has no safety net. Characterization tests against current behavior must be written **before** the move, not after — particularly for the persistence pipeline ordering, the feature-default steal semantics, and both cascades.
- **Host apps**: breaking. Any host using `/admin/ai/*` loses those routes and must build its own controllers against the new services. Because the package never shipped the components, in practice only hosts that already built a full admin UI are affected — and they keep their components, rewriting only the controllers.
- **Version**: `0.11.0`. Breaking, which a `0.x` minor permits.
- **Not in scope**: `ConversationUsageService` and the two backfill commands survive unchanged. The earlier surface audit flagged them as cut candidates, but per-bot cost aggregation is one of the things moving into the service layer here, so cutting the usage layer is a separate decision.

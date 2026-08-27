## Why

"ChatBot" only ever described the reactive, human-triggered half of what this package supports — a named, slugged, browser-facing character that responds when someone sends it a message. There has never been a concept for AI-driven work that starts on its own: a host observer reacting to a domain event, a scheduled sweep, anything that isn't "a human typed something." Nothing about `AiChatBot` or `AiChatBotConversationService` is wrong for what it does; the package is just missing the other half.

The package is pre-1.0, so this is a natural point to fix both at once: rename the existing persona concept to something that doesn't read as dated and doesn't collide with vocabulary the package (and laravel/ai) already use for other things, and add the missing "dispatched independently" concept alongside it.

## What Changes

- **BREAKING**: `AiChatBot` is renamed to `AiPersona` throughout — model, `ai_chat_bots` table (→ `ai_personas`), `AiChatBotManager` (→ `AiPersonaManager`), `AiChatBotConversationService` (→ `AiPersonaConversationService`, same five-collaborator constructor contract, just renamed), the `ai_chat_bot_id` foreign key on `ai_conversations`/`ai_interaction_logs` (→ `ai_persona_id`), and `AiChatBot::featureKey()`'s `chat-bot:*` prefix (→ `persona:*`).
- **BREAKING**: Existing `ai_system_feature_defaults.feature` rows using the `chat-bot:` prefix, and any host `feature_keys` config listing `chat-bot:*` keys, need updating to `persona:*`. A migration rewrites the prefix on stored rows; host-owned config is not something the package can migrate.
- Adds `AiOperator`: a persona-shaped config (`name`, `slug`, `ai_system_id`, `prompt_template`, `allowed_tools`) for bounded, single-shot AI work that a host dispatches itself — a queued job, not an HTTP endpoint the package owns.
- Adds `RunAiOperatorJob`, mirroring the existing `ProcessAiMemoryJob` pattern: any observer, listener, or command can `dispatch(new RunAiOperatorJob($operator, $context))`. The package owns no trigger/event system — dispatching is entirely the host's concern, consistent with how the package already has no routes or controllers.
- An operator run is bounded: one interpolated prompt, laravel/ai's existing agentic tool loop (same `CodeTalkerAgent::maxSteps()` cap chat turns already use), then done. No autonomous "keep going until some goal is met" loop.
- `AiOperator::prompt_template` supports dotted placeholders (e.g. `{{order.total}}`) interpolated against the `$context` array the dispatching code passes in — the same templating idea as `AiPersona::prompt_template`'s fixed placeholders, generalized to arbitrary caller-supplied context.
- An operator run is recorded as an `AiConversation` (feature `operator:{slug}`, `ai_persona_id` null, new nullable `ai_operator_id`), so it gets audit logging (`AiLlmMessage`), raw exchange capture, and cost tracking for free from infrastructure the package already has — no new logging tables.
- `Services/ChatBot/*`, `ConversationTurnRunner`, `ChatBotToolRegistry`, `ChatBotPresenter`, and `ChatBotStatusResolver` are **not** renamed in this change. They implement turn/tool-execution mechanics that are not persona-specific in code, only in surrounding vocabulary, and `AiOperator`'s runner is a new, separate, non-streaming code path — it does not run through `ConversationTurnRunner`. Renaming that namespace is unrelated churn this change does not need.

## Capabilities

### New Capabilities
- `ai-operator-dispatch`: `AiOperator` configuration, host-triggered dispatch via `RunAiOperatorJob`, bounded single-shot execution, dotted-placeholder prompt templating, and audit/cost tracking via a feature-keyed `AiConversation`.

### Modified Capabilities
- `ai-management-services`: "Chat bots are managed through a service" becomes "Personas are managed through a service" (same behavior, `AiPersonaManager`); conversation search's reference to "the related bot's name and slug" becomes "the related persona's name and slug".
- `chat-turn-library`: "Chat bot access rules are enforced by the service" becomes "Persona access rules are enforced by the service"; scenario wording updates from "chat bot"/"bot" to "persona" throughout.

Note: `frontend-integration-contract` is **not** touched by this change. Its "Inertia prop contract" and "Rendered component names are configurable" requirements describe `ai/ChatBot`/`ai/ChatBotsIndex` components, a `bot` prop, and a `code-talker.inertia.components.chat_bot` config key — none of which exist in the shipped package. Inertia support was removed entirely in 0.11.0 (`CHANGELOG.md`); those two requirements are pre-existing spec drift, not something this change introduces or needs to fix.

## Impact

- **Code**: `AiChatBot` → `AiPersona` (model, factory, manager, conversation service, presenter/status-resolver call sites, `SystemPromptBuilder`, `CodeTalkerConversationStore`); new migrations performing the table/column renames (existing, already-published migration files are never edited in place) plus new migrations for `ai_operators`/`ai_operator_id`; the README's "Chat Bots" section (→ "Personas") and a new "Operators" section; `CHANGELOG.md`.
- **New code**: `AiOperator` model + factory + migration, `AiOperatorManager` (mirroring the other `Services/Management/` managers), `RunAiOperatorJob`, an operator runner service, dotted-placeholder template interpolation, tests for all of the above.
- **Tests**: every test referencing `AiChatBot`/`makeBot()`/`AiChatBotManager`/`AiChatBotConversationService` updates its names; `AiChatBotConversationServiceTest`'s pinned five-positional-argument constructor test moves to `AiPersonaConversationServiceTest` with the same shape, just renamed.
- **Version**: breaking, but pre-1.0 — per project convention this ships as a MINOR bump, not a wait for 1.0.0.
- **Not in scope**: no autonomous multi-step operator loop, no package-owned scheduling/event system for triggering operators, no rename of `Services/ChatBot/*`/`ConversationTurnRunner`/`ChatBotToolRegistry`.

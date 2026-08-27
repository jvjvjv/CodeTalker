## Context

Today the package has exactly one persona concept, `AiChatBot`: a named, slugged, browser-facing character with a `prompt_template`, an access path/URL, and a `conversations()` history — every run of it starts because a human sent a message, and `ConversationTurnRunner` streams the response back over SSE.

Separately, `CodeTalkerAgent`/`AgentFactory` are laravel/ai's own execution vocabulary — a configured `instructions + tools + model` unit invoked via `prompt()`/`stream()`. Nothing product-facing wraps that execution layer for work that isn't a chat turn.

This change does two things that came out of the same conversation but are structurally independent: renames `AiChatBot` → `AiPersona` (terminology only — same shape, same behavior), and adds `AiOperator`, a new persona-shaped config for bounded, single-shot work a host dispatches itself rather than a human triggering it.

## Goals / Non-Goals

**Goals:**
- `AiPersona` replaces `AiChatBot` with identical behavior — a pure rename, not a redesign.
- `AiOperator` supports "a host observer/listener/job dispatches this with some context, it runs one bounded prompt → tool loop, then reports what happened" — nothing more.
- Reuse existing audit, cost-tracking, and tool-registry infrastructure for operator runs rather than building parallel logging.
- Keep the package's existing philosophy: no routes, no controllers, no package-owned trigger or scheduling system. The host dispatches; the package executes and records.

**Non-Goals:**
- No autonomous multi-step "keep going until some goal is met" loop. An operator run is exactly as bounded as a chat turn's agentic tool loop (`CodeTalkerAgent::maxSteps()`), just without a human sending the prompt.
- No package-owned event bus, webhook receiver, or cron registration for operators. `RunAiOperatorJob` is dispatched by host/package code that already knows when to run it — the same shape `ProcessAiMemoryJob` already uses from `AiConversationObserver`.
- No rename of `Services/ChatBot/*`, `ConversationTurnRunner`, `ChatBotToolRegistry`, `ChatBotPresenter`, or `ChatBotStatusResolver`. These implement turn/tool-execution mechanics that are not persona-specific in their actual code (they operate on `AiConversation`, not `AiChatBot`, already) — only the surrounding vocabulary says "chat bot." Renaming that namespace is unrelated churn.
- No backward-compatibility shim for the old `AiChatBot`/`chat-bot:*` names. The package is pre-1.0; per `CLAUDE.md` this ships as a documented breaking change, not a deprecation cycle.

## Decisions

**Rename scope is the product-facing surface, not the internal execution namespace.**
Renamed: the model, table, `AiPersonaManager`, `AiPersonaConversationService`, the `ai_persona_id` FK, Inertia component/prop names, the `persona` config key, and `featureKey()`'s prefix. Left alone: `Services/ChatBot/Conversation/*`, `ConversationTurnRunner`, `ChatBotToolRegistry`, `ChatBotPresenter`, `ChatBotStatusResolver`.
- *Alternative considered*: rename the whole `Services/ChatBot` namespace to `Services/Persona` for consistency. Rejected — those classes already key off `AiConversation`, not `AiChatBot`; `AiOperator`'s own runner needs the same tool-registry/turn-recording machinery, and renaming it to "Persona" would immediately be wrong for that reuse. The namespace name was already more accurate as "the conversation/turn machinery" than as "the chatbot machinery."

**An operator run is recorded as an `AiConversation`, not a new table.**
`ai_conversations.ai_chat_bot_id` is already nullable — a conversation without a persona already works today. Add a sibling nullable `ai_operator_id`, mutually exclusive with `ai_persona_id` in practice (never both), `feature` set to `operator:{slug}`. This means `AiLlmMessage` request/response logging, `RawExchangeContext` raw capture, and `ConversationUsageService` cost rollups all apply to operator runs with zero new code — they already operate on `AiConversation`/`AiLlmMessage`, not on the persona relation.
- *Alternative considered*: a dedicated `AiOperatorRun` table. Rejected — it would duplicate exactly what `AiConversation`/`AiLlmMessage` already do, for a run shape (one prompt, bounded tool loop, done) that a single-message `AiConversation` already models correctly.

**A new, non-streaming runner — not `ConversationTurnRunner`.**
`ConversationTurnRunner` exists to stream SSE frames to a browser and re-prompt on a max-tokens stop so a human waiting on an answer isn't cut off. Neither applies here: nothing is listening for SSE, and a bounded task that hits the token limit is a signal the operator's config or `AiSystem.max_tokens` needs attention, not something to silently continue past. The new runner calls the agent's non-streaming `prompt()`, records exactly one `AiLlmMessage` request/response pair (still via `RawExchangeContext` for raw capture), and reports the stop reason as part of the job's outcome — a max-tokens stop fails the job loudly (surfaced through the queue's normal failure handling) rather than being patched over.
- *Alternative considered*: reuse `ConversationTurnRunner`'s continuation-on-max-tokens loop for operators too. Rejected for now as unnecessary complexity for a bounded task; revisit if real usage shows operators regularly need more headroom than one attempt gives.

**Dotted-placeholder interpolation fails loud on a missing key.**
`AiOperator::prompt_template` placeholders (`{{order.total}}`) resolve against the `$context` array via `data_get()`. A placeholder that resolves to `null` (key genuinely absent, not just falsy) throws, rather than interpolating an empty string. Sending a model a task prompt with silently-blanked context risks driving tool calls against wrong/missing data; a loud failure surfaces through the job's normal `tries`/`backoff`/failure reporting instead. Mirrors this codebase's existing fail-closed posture (`HostGate` treating an unresolvable host as private).
- *Alternative considered*: interpolate blank and let the model notice something's missing. Rejected — the model has no way to distinguish "this field is genuinely empty" from "the host's context didn't include it," and by the time anyone notices, tool calls may already have run.

**`$context` is a plain, JSON-serializable array — not an Eloquent model.**
`RunAiOperatorJob(AiOperator $operator, array $context)`. Unlike `ProcessAiMemoryJob(AiConversation $conversation)`, which passes a model because the job needs to reload and mutate it, an operator's context is payload data interpolated into a prompt, not an entity the job re-fetches. A caller with an Eloquent model calls `->toArray()` (or curates a subset) before dispatching.

**Feature-key prefix changes from `chat-bot:` to `persona:`; `AiOperator` gets `operator:{slug}`.**
`AgentFactory::forFeature()` is already prefix-agnostic (a plain string lookup against `AiSystemFeatureDefault`), so no factory changes are needed beyond the rename. A migration rewrites existing `ai_system_feature_defaults.feature` values with the `chat-bot:` prefix to `persona:`; a host's own `feature_keys` config (a plain array of allowed strings) is not something a package migration can touch and must be updated by the host as part of upgrading.

**`AiOperatorManager` mirrors the existing five `Services/Management/` managers.**
Same shape as `AiPersonaManager` (nee `AiChatBotManager`): static `rules()`/`createRules()`/`updateRules()`, create/update/delete/list. No new pattern introduced.

## Risks / Trade-offs

- **Renaming `ai_chat_bot_id` → `ai_persona_id` and `ai_chat_bots` → `ai_personas` is a direct schema rename** (no add-new/backfill/drop-old two-phase migration) → acceptable because the package is pre-1.0 and this is already a documented breaking release; a host runs the migration during a deploy window like any other breaking upgrade.
- **No max-tokens continuation for operators** could mean a task that would have completed with one more continuation instead fails the job outright → mitigated by failing loud (visible in queue failure monitoring) rather than silently truncating; can be revisited without another breaking change if it proves too strict.
- **Throwing on an unresolved placeholder** could break an operator dispatch in production if a host's context shape drifts → this is the deliberate trade-off (loud beats silently wrong for a prompt that drives tool calls); the job's existing `tries`/`backoff` pattern (mirroring `ProcessAiMemoryJob`) gives the host a normal retry/alerting path.

## Migration Plan

1. Rename migrations (direct `Schema::rename`/`renameColumn`, no dual-write phase): `ai_chat_bots` → `ai_personas`; `ai_conversations.ai_chat_bot_id` → `ai_persona_id`; `ai_interaction_logs`' chat-bot fields → persona fields.
2. New migrations: `ai_operators` table; `ai_conversations.ai_operator_id` (nullable, FK, `nullOnDelete`).
3. Data migration: `AiSystemFeatureDefault` rows with `feature LIKE 'chat-bot:%'` rewritten to `persona:` + the same slug suffix.
4. Config: no Inertia-related config key exists to rename — that block was removed in 0.11.0. (An earlier draft of this design assumed a `code-talker.inertia.components.chat_bot` key still existed; it doesn't. Corrected during implementation.)
5. No rollback path beyond the migrations' own `down()` — consistent with how every other breaking change in this package's history has shipped (see `CHANGELOG.md`'s 0.11.0/0.12.0 entries).

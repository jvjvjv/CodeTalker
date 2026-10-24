## Context

Thinking is configured today by one nullable boolean, `ai_systems.enable_thinking`, read in two places:

- `AgentFactory::providerOptionsFor()` turns it into a request field for exactly two providers: Anthropic gets `thinking: {type: enabled, budget_tokens: 1024}` (only when `max_tokens > 1024`), and LM Studio gets `reasoning: on|off` when its model is reported reasoning-capable. OpenAI, xAI and Gemini get nothing, so their reasoning is never requested or shown.
- `AgentFactory::forSystem()` passes it to `CodeTalkerAgent` as `showThinking`, which only `ReasoningOpenAiCompatibleGateway` reads. Every other provider's reasoning reaches the browser whenever the provider happens to return it.

Relevant facts about laravel/ai 0.9.1 that shape the design:

- Every text gateway merges `providerOptions` straight into the request: top level for Anthropic, OpenAI and xAI (both on the Responses API), into `generationConfig` for Gemini, top level for openai-compatible. So a mapper only has to produce the right array; no gateway changes are needed for the request side.
- The Anthropic gateway streams `thinking_delta`, the OpenAI gateway streams `response.reasoning_summary_text.delta`, and the Gemini gateway streams parts marked `thought`. All three become `ReasoningDelta`, which `StreamTranslator` turns into `reasoning_block_delta`. None of them returns readable reasoning unless the request asks for it (`display: summarized`, `reasoning.summary`, `includeThoughts`).
- The Anthropic gateway's tool-choice logic only checks `isset($providerOptions['thinking'])`, so adaptive thinking goes through the same path as budget thinking.
- The host's `config.provider_options` passthrough is merged *after* the package's own options, so it overrides them key by key (shallowly).

Provider surfaces the mapping has to respect (current as of this change; pinned by tests, see Risks):

| Provider | Request shape | Levels | Can disable? |
|---|---|---|---|
| Anthropic, Claude 4.6 | `thinking: {type: adaptive}` + `output_config.effort` | low, medium, high, max | yes — omit `thinking` |
| Anthropic, Opus 4.7 / 4.8 | same | low … max incl. xhigh | yes — omit `thinking` |
| Anthropic, Opus 5 / Sonnet 5 / Haiku 5.5 | same | low … max | only by sending `thinking: {type: disabled}` (omitting runs adaptive) |
| Anthropic, Opus 5.5 / Sonnet 5.5 / Fable / Mythos | same | low … max | no — `disabled` and `budget_tokens` return 400 |
| Anthropic, ≤ 4.5 | `thinking: {type: enabled, budget_tokens: N}`, `1024 ≤ N < max_tokens` | budget | yes — omit `thinking` |
| OpenAI reasoning models | `reasoning: {effort, summary: auto}` | model-dependent subset of none, minimal, low, medium, high, xhigh | only where `none` is accepted |
| xAI | `reasoning: {effort}` | grok-3-mini: low, high; grok-4 family: none (always reasons, rejects the field) | no |
| Gemini 3 | `thinkingConfig: {thinkingLevel, includeThoughts}` | pro: low, high; flash: minimal, low, medium, high | no |
| Gemini 2.5 | `thinkingConfig: {thinkingBudget, includeThoughts}` | budget | flash: `0`; pro: no (minimum 128) |
| LM Studio | `reasoning: off\|low\|medium\|high\|on` | off, low, medium, high | yes |

## Goals / Non-Goals

**Goals:**
- One level field whose meaning is the same on every provider, translated in one place per provider.
- Never send a request the provider is known to reject because of a thinking parameter.
- One visibility rule for every provider, enforced where the turn's events are produced.
- An upgrade that keeps old rows working before the migration runs and can be rolled back.

**Non-Goals:**
- Per-turn or per-persona thinking overrides. The level lives on `AiSystem`, as `enable_thinking` did.
- Anthropic's newer controls beyond effort — task budgets, per-message effort, `display: "updates"`, `between_tools`.
- Fixing the sampling-parameter problem on current Claude models (`temperature` is rejected on Opus 4.7+ and Sonnet 5+; the factory still sends it). It is a real, adjacent bug, but it is not about thinking and deserves its own change.
- Usage or cost accounting for reasoning tokens. They are already inside the providers' output token counts.
- Live capability discovery from provider model APIs. The tables are static (see Decision 3).

## Decisions

### 1. A named, ordered scale — not tokens, not integers

`Enums\ThinkingLevel` is a string-backed enum: `off`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max`, with an `ordinal()` used for clamping. `null` on the model means "send nothing".

The scale is the union of the providers' own words, so most mappings are the identity and the admin UI can show labels a reader already recognises from provider docs.

*Alternatives:* a token budget (deprecated on Anthropic, absent on OpenAI and Gemini 3 — it would have to be converted back into levels everywhere); an integer 0–5 (loses the provider vocabulary and invites "what does 3 mean on Gemini?"); a separate `show_thinking` boolean beside the level (two fields that can contradict each other — `off` + show, `high` + hide — for no use case anyone has asked for).

### 2. One mapper per provider behind a resolver

`Services/LaravelAi/Thinking/` holds a `ThinkingMapper` contract with one implementation per provider (`AnthropicThinking`, `OpenAiThinking` — shared by OpenAI and xAI with different tables — `GeminiThinking`, `LmStudioThinking`, and a `NoThinking` for openai-compatible and voice providers). Each answers two questions:

- `profile(string $model, AiSystem $system): ThinkingProfile` — the supported levels, whether `off` can actually stop reasoning, and whether the level controls effort or only visibility.
- `options(ThinkingLevel $level, string $model, ?int $maxTokens, AiSystem $system): array` — the provider-options fragment for an already-clamped level.

`ThinkingResolver` picks the mapper from `AiProvider`, clamps the requested level against the profile (next lower supported level, else next higher — Decision 4), and returns the options plus the visibility decision. `AgentFactory::providerOptionsFor()` replaces its two `enable_thinking` branches with one call to the resolver and keeps the existing passthrough merge after it, so a host can still override anything.

This mirrors how the package already handles providers elsewhere (`AiProvider::toLaravelAiDriver()`, `supportsRole()`), and keeps each provider's quirks in a file small enough to test exhaustively.

*Alternative:* a `match` inside `AgentFactory`. It is where the code lives today, and it is already the reason two of five providers get no thinking at all — the factory is the wrong size of place for seven model families.

### 3. Model families are recognised by static prefix tables

Each mapper holds an ordered list of `model-id prefix → profile` rules, first match wins, with a provider-level default for unrecognised ids. For Anthropic, OpenAI and Gemini the default is the *newest* family's profile (adaptive + effort, effort, thinking level), because new model ids appear far more often than old ones are configured; xAI defaults to "no effort field", the safe choice for its always-reasoning models. LM Studio does not use a table: it keeps reading the reasoning flag from `model_capabilities`, which LM Studio reports itself.

*Alternatives:* querying `GET /v1/models` capabilities live (a network call on the agent build path, which runs every turn — the same reason the remote MCP registry reads only from the database); storing a profile on `AiSystem` (pushes knowledge of every provider's thinking matrix onto every admin). Recognising an unknown id wrongly is recoverable through `config.provider_options`; a stalled turn is not.

### 4. Clamping prefers lower, and `off` falls back to the lowest effort

A requested level the profile doesn't offer becomes the nearest supported level below it, or above it if there is none below. Lower is preferred because the usual reason for an unsupported level is the top of the scale (`xhigh`, `max`), and silently spending more than asked is the worse surprise.

`off` on a model that cannot stop reasoning becomes that model's lowest effort with reasoning hidden. Failing the request instead would make a perfectly reasonable stored setting break the bot after a model change; omitting the parameter would run at the provider's default effort (`medium` or `high`), the opposite of what `off` asked for.

### 5. Anthropic sends the shape its model family accepts

- Effort families: `thinking: {type: adaptive, display: summarized}` plus `output_config: {effort: <level>}`. `display: summarized` is required because current Claude models default to `omitted`, which streams empty thinking and would look like a long silence. For `off`: omit `thinking` (4.6 – 4.8), send `{type: disabled}` (Opus 5 / Sonnet 5 / Haiku 5.5), or clamp to `low` and hide (Opus 5.5 / Sonnet 5.5 / Fable / Mythos).
- Budget families: `thinking: {type: enabled, budget_tokens: N}` with N by level — minimal and low 1024, medium 4096, high 8192, xhigh 16384, max 32768 — capped at `max_tokens - 1`. No thinking is sent when `max_tokens ≤ 1024`, which keeps today's guard.

The old fixed `budget_tokens: 1024` is not kept for effort families: it is rejected with a 400 on Opus 4.7 and later.

### 6. Visibility is decided once and enforced in the translator

The resolver's visibility decision is `level !== null && level !== off`. It reaches the agent as the existing `showThinking` constructor argument (no new public surface), and `AiPersonaConversationService` passes `$agent->showThinking()` into a new optional `StreamTranslator(bool $showReasoning = true)` constructor argument. With it false, the translator drops `ReasoningDelta` events, so neither the browser nor `TurnRecorder` (which accumulates from the translated events) ever sees them.

`ReasoningOpenAiCompatibleGateway` keeps its own gate; it now simply agrees with the translator. The translator's default stays `true` so a host constructing it directly keeps today's behavior. The `reasoning_block_delta` wire format does not change, so the README event table and `code-talker.d.ts` are untouched.

*Alternative:* rely only on not *requesting* reasoning. That fails for models that return reasoning unasked — LM Studio's qwen-style models and any `off` that had to clamp to `low`.

### 7. `enable_thinking` is kept in sync for one release, then removed

- Migration: add nullable `thinking_level` (string, 20), backfill from `enable_thinking` (`true → medium`, `false → off`, `null → null`). `medium` rather than `low` for `true` because on every effort provider "thinking on with nothing more said" already runs at the provider default, which is `medium` or `high`; on Anthropic budget models it raises the old 1024-token budget to 4096, which the changelog states.
- `AiSystem::thinkingLevel(): ?ThinkingLevel` reads `thinking_level`, falling back to translating `enable_thinking` when the column is absent or null and the flag is set. That keeps an upgraded-but-unmigrated host thinking as before, the same courtesy the `role` change extended.
- `AiSystemManager` accepts `thinking_level`; when only `enable_thinking` is supplied it translates it; when both are supplied the level wins. Every write sets **both** columns consistently (`enable_thinking = level above off`, or null with a null level), so rolling the package back reads the right boolean.
- `enable_thinking` stays fillable and cast, marked `@deprecated`. A later release drops the input and the column.

### 8. Level discovery for the admin UI

`AiSystemManager::thinkingLevels(AiProvider|string $provider, string $model, ?array $modelCapabilities = null): array{levels: list<string>, controls_effort: bool}` asks the resolver for the profile. A level selector shows `levels`; when `controls_effort` is false the host renders a show/hide control instead (the result is then `['off', 'medium']`). `off` is always listed for chat providers, because even where it cannot stop reasoning it still hides it. Voice-only providers return no levels.

This is the "guidance" half of the change: the README documents the call and a minimal Blade/Inertia example of the selector replacing the checkbox. The package still ships no UI.

## Risks / Trade-offs

- [Provider tables go stale as models ship] → Each mapper's table is pinned by a data-provider test listing real model ids and expected request bodies, so a table edit is one line plus one test row. The default for unknown ids is the newest family, and `config.provider_options` can override any key. The README says which ids each table recognises.
- [`provider_options` merges shallowly, so overriding `output_config` replaces the effort too] → Documented. Hosts that need it pass the full object.
- [Upgraded Anthropic systems spend more] → `medium` effort/4096-token budget instead of a 1024 budget. Called out as a breaking change; hosts can set `low`.
- [`off` cannot always mean "no reasoning tokens"] → On models that cannot disable thinking, `off` means lowest effort, hidden. `thinkingLevels()` reports this, and the README states it plainly.
- [Hiding reasoning in the translator does not stop it being billed or logged raw] → Correct and intended: `AiLlmMessage` and raw exchange capture record what the provider returned. Visibility is about the turn's events and the stored reply only.
- [OpenAI's effort subset differs across model generations] → Clamping makes an over-ask safe; the table records which generations accept `none`, `minimal`, and `xhigh`.

## Migration Plan

1. Ship the migration with the release; hosts re-publish migrations and run them.
2. Before the migration runs, `AiSystem::thinkingLevel()` falls back to `enable_thinking`, so turns behave as the backfill will make them.
3. Rollback: the previous package version reads `enable_thinking`, which every write in this release keeps correct. The new column can be left in place; its down migration drops it.
4. A later release removes `enable_thinking` from the model, the manager rules and the table, with its own migration.

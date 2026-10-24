## Why

`AiSystem::enable_thinking` is a boolean, and the providers it drives no longer think in booleans. Anthropic, OpenAI, Gemini, xAI and LM Studio all now take a graded *level* of reasoning (`low` … `max`), several current models cannot turn thinking off at all, and the one budget the package sends today (Anthropic `budget_tokens: 1024`) is rejected with a 400 by Claude Opus 4.7 and every later Claude model. A switch cannot express "think a little" vs "think hard", and on the newest models it actively breaks the request.

## What Changes

- Add `AiSystem::thinking_level`, a nullable string backed by a new `ThinkingLevel` enum with one ordered, provider-neutral scale: `off`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max`. `null` means "send nothing; use the provider's default".
- Translate the level into each provider's own request shape at agent build time, clamping to the nearest level the provider and model actually support:
  - Anthropic: adaptive thinking plus `output_config.effort` on models that take it; `budget_tokens` scaled by level (and bounded by `max_tokens`) on older models; never a `disabled` request to a model that rejects it.
  - OpenAI and xAI (Responses API): `reasoning.effort`, with `reasoning.summary` requested so reasoning can stream.
  - Gemini: `thinkingConfig` with `thinkingLevel` or `thinkingBudget` by model generation, and `includeThoughts`.
  - LM Studio: its native `reasoning` field, which already speaks levels.
  - `openai-compatible`: nothing by default (no common standard); hosts can still pass fields through `config.provider_options`.
- Decide reasoning visibility from the level in one place: reasoning reaches the turn's events only when the resolved level is above `off`. The gate moves from the openai-compatible gateway alone to the stream translator, so it holds for every provider.
- Add `AiSystemManager::thinkingLevels($provider, $model)` so an admin UI can render a level selector offering only the levels that system can honor.
- **BREAKING**: `enable_thinking` is deprecated. A migration adds `thinking_level` and backfills it (`true` → `medium`, `false` → `off`, `null` → `null`). For this release the manager still accepts `enable_thinking` and translates it when `thinking_level` is absent; the column and the input are removed in a later major-for-0.x release.
- **BREAKING**: An Anthropic system with thinking enabled sends adaptive thinking with an effort instead of a fixed 1024-token budget on current models, so its token spend and latency change.
- Document the level scale, the per-provider mapping, and how to replace a thinking checkbox with a level selector in the README.

## Capabilities

### New Capabilities
- `thinking-levels`: the provider-neutral level scale, how a level becomes each provider's request, clamping, visibility, and the upgrade path from `enable_thinking`.

### Modified Capabilities
- `ai-management-services`: the system manager validates `thinking_level`, translates the deprecated `enable_thinking` input, and lists the levels a provider/model pair supports.

## Impact

- **Code**: new `Enums/ThinkingLevel`; new `Services/LaravelAi/Thinking/` (one mapper per provider plus a resolver); `AgentFactory::providerOptionsFor()` and `forSystem()`; `CodeTalkerAgent::showThinking()`; `StreamTranslator`; `ReasoningOpenAiCompatibleGateway` (keeps its gate, now fed by the level); `AiSystem` (fillable, cast); `AiSystemManager` rules and a new `thinkingLevels()` method.
- **Database**: one new migration (add + backfill `thinking_level`). Hosts re-publish migrations and run them.
- **Public API**: `AiSystem::thinking_level`, `ThinkingLevel`, `AiSystemManager::thinkingLevels()`. The turn event wire format (`reasoning_block_delta`) is unchanged.
- **Docs**: README AI Systems field table, a new "Thinking levels" section, and the "Migrating between versions" row at release time.
- **Tests**: `AgentFactoryTest`'s `enable_thinking` cases move to levels; new per-provider mapping tests pin the exact request bodies.

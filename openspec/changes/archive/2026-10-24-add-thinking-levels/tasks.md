## 1. Level scale and storage

- [x] 1.1 Add `Enums/ThinkingLevel` (`off`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max`) with `ordinal()` and a `fromLegacyFlag(?bool)` helper (`true → medium`, `false → off`, `null → null`); verify with a unit test covering order and the legacy mapping
- [x] 1.2 Add a migration that adds nullable `ai_systems.thinking_level` (string, 20) and backfills it from `enable_thinking`, with a down migration that drops the column; verify with a migration test seeding true/false/null rows and asserting `medium`/`off`/null
- [x] 1.3 Add `thinking_level` to `AiSystem` fillable and casts (enum cast), mark `enable_thinking` `@deprecated`, and add `AiSystem::thinkingLevel(): ?ThinkingLevel` that falls back to `enable_thinking` when the column is null or not loaded; verify with model tests including a row selected without `thinking_level`

## 2. Provider mappers

- [x] 2.1 Add `Services/LaravelAi/Thinking/ThinkingProfile` (supported levels, `canDisable`, `controlsEffort`) and the `ThinkingMapper` contract (`profile()`, `options()`); verify `vendor/bin/phpunit` still passes
- [x] 2.2 Add `ThinkingResolver` that picks a mapper by `AiProvider`, clamps a level against the profile (lower first, then higher; `off` on a non-disableable profile → lowest level, hidden), and returns options plus the visibility decision; verify with a unit test over a fake profile covering clamp-down, clamp-up, `off` fallback, and null level
- [x] 2.3 Implement `AnthropicThinking` with the prefix table from design Decision 5 (adaptive + `output_config.effort` + `display: summarized`; `disabled` for Opus 5 / Sonnet 5 / Haiku 5.5; no-disable for Opus 5.5 / Sonnet 5.5 / Fable / Mythos; level-scaled `budget_tokens` below `max_tokens` for ≤ 4.5, none when `max_tokens ≤ 1024`); verify with a data-provider test pinning the exact options for one model id per family at every level
- [x] 2.4 Implement `OpenAiThinking` with separate OpenAI and xAI tables (`reasoning.effort` clamped per model generation, `reasoning.summary: auto` above `off`, no `reasoning` field for non-reasoning models and grok-4); verify with a data-provider test pinning options per model id and level
- [x] 2.5 Implement `GeminiThinking` (`thinkingConfig.thinkingLevel` for Gemini 3, level-scaled `thinkingBudget` for 2.5 with `0` only where flash allows it, `includeThoughts` above `off`); verify with a data-provider test, and assert through a fake Gemini request that `thinkingConfig` lands inside `generationConfig`
- [x] 2.6 Implement `LmStudioThinking` (native `reasoning` value from the clamped level, only when `model_capabilities.reasoning` is true) and `NoThinking` (openai-compatible and voice providers: no options, visibility-only profile, empty profile for non-chat); verify with unit tests

## 3. Agent build and visibility

- [x] 3.1 Replace both `enable_thinking` branches in `AgentFactory::providerOptionsFor()` with the resolver, keeping the `config.provider_options` passthrough merged last; verify by updating `AgentFactoryTest` (the existing `enable_thinking` cases become level cases) and adding a case where passthrough overrides a resolver key
- [x] 3.2 Pass the resolver's visibility decision as `showThinking` in `AgentFactory::forSystem()`; verify `test_agent_show_thinking_mirrors_enable_thinking` is rewritten against levels (above `off` → true; `off`, null, and a clamped `off` → false)
- [x] 3.3 Add an optional `bool $showReasoning = true` constructor argument to `StreamTranslator` that drops `ReasoningDelta` when false, and pass `$agent->showThinking()` from `AiPersonaConversationService`; verify with a translator unit test and a turn test asserting no `reasoning_block_delta` events and null `reasoning_content` for an `off` system whose fake provider streams reasoning
- [x] 3.4 Point `ReasoningOpenAiCompatibleGateway`'s doc comment at the level instead of `enable_thinking` (behavior unchanged); verify `ReasoningOpenAiCompatibleGatewayTest` passes

## 4. Management service

- [x] 4.1 Add `thinking_level` to `AiSystemManager` shared rules (`nullable`, `Rule::enum(ThinkingLevel::class)`), translate `enable_thinking` when `thinking_level` is absent, let `thinking_level` win when both are present, keep the stored level when neither is supplied on update, and write both columns consistently on every save; verify with manager tests for each scenario in the `ai-management-services` delta
- [x] 4.2 Add `AiSystemManager::thinkingLevels($provider, $model, ?array $modelCapabilities = null)` returning `{levels, controls_effort}` from the resolver; verify with tests for an effort model, a non-disableable model (still lists `off`), a visibility-only provider (`['off', 'medium']`, `controls_effort: false`), and `elevenlabs` (empty)

## 5. Documentation

- [x] 5.1 Update the README AI Systems field table (`thinking_level` added, `enable_thinking` marked deprecated) and add a "Thinking levels" section: the scale, the per-provider mapping and recognised model families, clamping and `off` semantics, the `provider_options` shallow-override caveat, and an example of replacing a thinking checkbox with a selector driven by `thinkingLevels()`; verify every code reference in the section exists
- [x] 5.2 Update the local `CLAUDE.md` architecture notes (thinking resolver location, visibility enforced in `StreamTranslator`, both columns written for rollback); verify by reading the section back

## 6. Verification

- [x] 6.1 Run `composer test` and `openspec validate add-thinking-levels --strict`; both pass

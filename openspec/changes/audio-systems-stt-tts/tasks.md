## 1. Role modeling

- [ ] 1.1 Add `Enums\AiSystemRole` with `Chat = 'chat'`, `Audio = 'audio'`, `Transcription = 'transcription'`, plus a `values()` helper matching `AiProvider`'s
- [ ] 1.2 Add migration `add_role_to_ai_systems_table` — a **string** column, default `'chat'`, indexed, nullable false. Not a DB enum: the package supports multiple DB drivers, and a future role would otherwise be a schema migration on every host
- [ ] 1.3 Add `role` to `AiSystem::$fillable` and cast it to `AiSystemRole`
- [ ] 1.4 Add `AiSystem::scopeRole(AiSystemRole $role)`; leave `scopeActive()` and `defaultForFeature()` alone
- [ ] 1.5 Confirm no backfill command is needed — the column default covers existing rows — and verify by migrating a DB seeded with pre-change records

## 2. Provider support

- [ ] 2.1 Add `AiProvider::ElevenLabs = 'elevenlabs'`
- [ ] 2.2 Map it in `toLaravelAiDriver()` to **`eleven`**, not `elevenlabs` — laravel/ai registers it via `AiManager::createElevenDriver` and `Lab::ElevenLabs = 'eleven'`. Getting this wrong fails at provider resolution with a message that does not name the cause
- [ ] 2.3 Add `AiProvider::supportsRole(AiSystemRole): bool` encoding the gateway table in `design.md`. Derive it by re-reading which providers in `vendor/laravel/ai/src/Providers/` implement `AudioProvider` and `TranscriptionProvider` rather than trusting the table — the dependency may have moved
- [ ] 2.4 Confirm `ElevenLabs::requiresApiKey()` returns true under the existing implementation (it is neither `OpenAICompatible` nor `LmStudio`, so it should) and that no other branch in the enum needs a new case
- [ ] 2.5 Grep for every `match` over `AiProvider` in `src/` and confirm each either handles the new case or has a `default` — `AgentFactory::supportsWebSearch()`, `timeoutFor()`, and `providerOptionsFor()` are the known ones. A non-exhaustive `match` throws `UnhandledMatchError` at runtime

## 3. Credential resolution

- [ ] 3.1 Add `providers.elevenlabs.base_url` to `config/code-talker.php` with an env fallback, following the existing provider block style
- [ ] 3.2 Add the `elevenlabs` branch to `AiSystemProviderConfigurator::resolveUrl()` — or confirm the existing generic `config("code-talker.providers.{$provider->value}.base_url")` line already covers it, in which case make no change and note that in the task
- [ ] 3.3 Verify `providerFor()` needs **no** other change: the audio and transcription gateways read the same `driver`/`key`/`url` config keys as the text gateways. If this turns out false, stop and revisit Decision 2 in `design.md` rather than forking the configurator

## 4. Shared resolution

- [ ] 4.1 Add `Services\AiSystemResolver` with `forFeature(string $feature, AiSystemRole $expected): AiSystem`, applying three guards: the feature default exists, the system is active, and its role matches
- [ ] 4.2 Move the body of `AgentFactory::systemForFeature()` into the resolver and delegate, passing `AiSystemRole::Chat`. **Keep the public signature, return type, and exception messages** — it is documented public API, and its "No default AI system configured for feature" / "is inactive" messages are the ones hosts see
- [ ] 4.3 Add a distinct exception message for the role mismatch that names both the expected and the actual role
- [ ] 4.4 Run `composer test` before writing any audio code — the extraction must be provably behavior-preserving on its own

## 5. The services

- [ ] 5.1 Create `Services/Audio/SpeechService` with `forSystem(AiSystem, string $text, ?string $voice = null, ?string $instructions = null): AudioResponse` and a `forFeature()` counterpart
- [ ] 5.2 Create `Services/Audio/TranscriptionService` with `forSystem(AiSystem, TranscribableAudio|UploadedFile $audio, ?string $language = null, bool $diarize = false): TranscriptionResponse` and a `forFeature()` counterpart
- [ ] 5.3 Both resolve the provider name via `AiSystemProviderConfigurator::providerFor()` and pass `$system->model` straight through — null is valid and means "use the provider default"
- [ ] 5.4 Both reject a record whose role does not match, and an inactive record, via `AiSystemResolver`
- [ ] 5.5 Wrap each provider call in `RawExchangeRecorder::capture(RawExchangeFrame::forSystem($system, $configurator), fn () => ...)`, mirroring `AiMemoryService::analyzeConversation()`
- [ ] 5.6 Return laravel/ai's `AudioResponse` / `TranscriptionResponse` unwrapped — no package DTOs (see `design.md`, "Service surface")
- [ ] 5.7 Register both in `CodeTalkerServiceProvider` if they need container wiring beyond autowiring; prefer autowiring

## 6. Validation and admin

- [ ] 6.1 Add `role` validation to `StoreAiSystemRequest` and `UpdateAiSystemRequest` — required, in `AiSystemRole::values()`
- [ ] 6.2 Add a cross-field rule rejecting provider/role pairs where `supportsRole()` is false, with a message naming the provider and role rather than a generic "invalid" string
- [ ] 6.3 Confirm the `api_key` conditional rules still behave for ElevenLabs records (it requires a key)
- [ ] 6.4 Add a role selector to the `AiSystem` create/edit admin payloads and a role column to the index listing
- [ ] 6.5 Make the admin provider list role-aware, so choosing `audio` stops offering `lm-studio` / `anthropic` / `grok`
- [ ] 6.6 Decide and document what `AiSystemController`'s "fetch models" action does for a non-chat role — `ProviderModelsClient` lists chat models. Simplest defensible answer: leave the model field free text for audio roles and skip the fetch, since laravel/ai's defaults already cover the common case

## 7. Tests

- [ ] 7.1 Table-driven test over all provider × role pairs asserting `supportsRole()` matches the design table
- [ ] 7.2 Form-request tests: a valid audio record saves; an `lm-studio` + `audio` record fails validation with the cross-field message
- [ ] 7.3 Configurator test asserting an ElevenLabs record produces `driver => eleven`
- [ ] 7.4 `SpeechService` tests using `Audio::fake()` + `assertGenerated`: happy path, wrong-role rejection, inactive-record rejection, blank model reaching the gateway as null
- [ ] 7.5 `TranscriptionService` tests using `Transcription::fake()`, same four cases, plus one asserting `diarize()` is forwarded
- [ ] 7.6 Regression test that `AgentFactory::systemForFeature()` still resolves chat records unchanged and now rejects an audio record
- [ ] 7.7 Migration test that a record created before the change resolves as `AiSystemRole::Chat`
- [ ] 7.8 Run `composer test` — the full suite, with no existing test modified except where the role column legitimately changes a factory

## 8. Documentation and wrap-up

- [ ] 8.1 Add an **Audio Systems** section to `README.md` after "AI Systems": creating an audio/transcription record, the provider support table, and both service surfaces with a usage example each
- [ ] 8.2 Document that `lm-studio` and `openai-compatible` support neither role, so a local-chat deployment needs a separate cloud record for voice — this is the single most likely point of confusion
- [ ] 8.3 Document that `raw_exchanges.providers` defaults to `lm-studio`, so audio exchanges are captured only if a host adds `openai`/`elevenlabs` or sets `all`
- [ ] 8.4 State plainly in the README that audio calls are **not** cost-tracked and not written to `AiLlmMessage`, with the reason — so the omission reads as a decision rather than an oversight
- [ ] 8.5 Update `CLAUDE.md`: note the `role` column under the `AiSystem` description, add `Services/Audio/` to the architecture map, and record that `openai-compatible` cannot serve audio roles
- [ ] 8.6 Add a CHANGELOG entry under a new minor version — New Features only, no Breaking Changes, since the column defaults to `chat`
- [ ] 8.7 Re-read `design.md`'s deferred-scope section against what was built and confirm nothing crept in

# Design — Audio Systems (STT/TTS)

## What laravel/ai actually provides

Verified against `vendor/laravel/ai` at `^0.9`. Everything below is implemented upstream; none of it needs building.

**TTS** — `Laravel\Ai\Audio::of(string $text)` returns `PendingAudioGeneration` with `voice()`, `male()`, `female()`, `instructions()`, `timeout()`, then `generate()` or `queue()`. Returns `AudioResponse(base64Body, Meta, 'audio/mpeg')`. The OpenAI gateway posts to `audio/speech` and maps the sentinel voices `default-male` → `ash` and `default-female` → `alloy`.

**STT** — `Laravel\Ai\Transcription::of(TranscribableAudio|UploadedFile|string)` plus `fromBase64()`, `fromPath()`, `fromStorage()`, `fromUpload()`. Returns `PendingTranscriptionGeneration` with `language()`, `diarize()`, `timeout()`. Returns `TranscriptionResponse(text, Collection<TranscriptionSegment>, Usage, Meta)`. The OpenAI gateway posts to `audio/transcriptions` with `response_format` of `diarized_json` when diarizing, and throws `LogicException` if `diarize` is combined with a `prompt` provider option.

**Gateway coverage** — read off which providers implement `AudioProvider` / `TranscriptionProvider`:

| Provider class | audio | transcription |
| --- | :--: | :--: |
| `OpenAiProvider` | ✅ | ✅ |
| `GeminiProvider` | ✅ | ✅ |
| `ElevenLabsProvider` | ✅ | ✅ |
| `OpenRouterProvider` | ✅ | ✅ |
| `MistralProvider` | ❌ | ✅ |

Provider defaults: OpenAI `gpt-4o-mini-tts` / `gpt-4o-transcribe-diarize`; ElevenLabs `eleven_multilingual_v2` / `scribe_v2`. laravel/ai's own `config/ai.php` sets `default_for_audio` and `default_for_transcription` to `openai` — irrelevant here, since this package always passes an explicit provider name.

**Driver key gotcha**: ElevenLabs registers as `eleven`, not `elevenlabs` (`AiManager::createElevenDriver`, `Lab::ElevenLabs = 'eleven'`). The package-facing `AiProvider` value is `elevenlabs` for readability; `toLaravelAiDriver()` does the translation, exactly as it already does for `grok` → `xai`.

## Decision 1 — role as a column on `AiSystem`

**Chosen.** Add `role` to `ai_systems`; an audio endpoint is an ordinary `AiSystem` row.

The alternatives were capability booleans (`supports_audio`, `supports_transcription`, alongside the existing `supports_tools`) and a separate `AiVoiceSystem` model.

Booleans were rejected because they cannot express *"this record is only for TTS"* and leave resolution ambiguous when two records qualify. A separate model was rejected because it duplicates roughly 80% of `AiSystem` — encrypted `api_key`, `base_url`, `is_active`, soft deletes, admin CRUD — to buy only the guarantee that voice records never appear in a chat-system dropdown, which a scope achieves for free.

The role column is what makes **Decision 2** possible, which is the real payoff.

Implementation notes:

- String column, default `'chat'`, indexed — matching how `provider` is modeled (string column, PHP enum on top). A DB-level enum would fight the package's multi-driver support and make future roles a migration on every host.
- Existing rows are covered by the column default; no backfill command, unlike `ai:backfill-system-capabilities`.
- `AiSystem::scopeRole()` for filtering. `defaultForFeature()` is unchanged — role checking belongs at resolution, not on the model.

## Decision 2 — no second configurator

`AiSystemProviderConfigurator::providerFor()` builds `['driver' => ..., 'key' => ..., 'url' => ...]` and registers it as `ai.providers.code-talker-system-{id}`. That is *already* everything an audio provider needs — the audio and transcription gateways read the same three keys as the text gateways.

So the configurator takes exactly one edit: an `elevenlabs` base-URL fallback in `resolveUrl()`. No parallel credential path, no second encryption surface, no divergence when someone later fixes a bug in one and not the other.

This is the concrete reason Decision 1 chose a column over a separate model: a separate model would have needed either its own configurator or a generalized one taking an interface, and the generalization would have earned nothing.

## Decision 3 — validate provider/role at the form request

`AiProvider::supportsRole(AiSystemRole): bool` encodes the gateway table above, in the same style as the existing `requiresApiKey()`:

| Provider | chat | audio | transcription |
| --- | :--: | :--: | :--: |
| `anthropic` | ✅ | ❌ | ❌ |
| `openai` | ✅ | ✅ | ✅ |
| `gemini` | ✅ | ✅ | ✅ |
| `grok` | ✅ | ❌ | ❌ |
| `elevenlabs` | ❌ | ✅ | ✅ |
| `openai-compatible` | ✅ | ❌ | ❌ |
| `lm-studio` | ✅ | ❌ | ❌ |

Without this guard, saving an LM Studio record with `role = audio` succeeds and fails later at the worst possible moment — `InvalidArgumentException` from inside a gateway, mid-request, with a message referring to laravel/ai internals the host developer has never read. The validation converts a confusing runtime failure into an obvious form error.

`ElevenLabs` being chat-incapable is enforced by the same table, so it cannot be selected for a chat system.

## Decision 4 — one resolver, shared with the chat path

`AgentFactory::systemForFeature()` currently resolves a feature default and applies two guards (exists, is active). The audio services need identical guards plus a third: the record must have the expected role.

Rather than copy ten lines twice, extract `Services\AiSystemResolver` with `forFeature(string $feature, AiSystemRole $expected): AiSystem`. `AgentFactory::systemForFeature()` **keeps its public signature and return type** and delegates, passing `AiSystemRole::Chat`. It is public API — `forFeature()` is documented in the README — so it must not change shape.

This closes a real gap: today, mapping `chat-bot:support` to a record and later switching that record's provider gives no role protection at all. After this change, pointing a chat feature at an audio record fails loudly at resolution.

## Decision 5 — capture raw exchanges, log nothing else

`RawExchangeRecorder::register()` installs a global HTTP middleware, but `shouldCapture()` returns null unless a frame is on the context. Audio calls therefore need an explicit `capture()` wrapper — one call, mirroring `AiMemoryService::analyzeConversation()`:

```php
$this->rawExchanges->capture(
    RawExchangeFrame::forSystem($system, $this->configurator),
    fn () => Audio::of($text)->voice($voice)->generate($providerName, $system->model),
);
```

`RawExchangeFrame::forSystem()` needs no changes — its `aiConversationId` and `aiLlmMessageId` are already nullable.

Note that `raw_exchanges.providers` defaults to `lm-studio`, so audio exchanges are captured only when a host opts in by adding `openai`/`elevenlabs` or setting `all`. That is the documented behavior of the setting and is left alone; the README should say so rather than the code special-casing audio.

**`AiLlmMessage` logging is cut.** The table's `ai_conversation_id` is non-nullable, and a host calling `SpeechService` directly has no conversation. Making the column nullable to accommodate conversation-less rows is speculative work whose only consumer arrives in change 3, which supplies a conversation anyway.

**Usage and cost tracking is cut**, for the reason given in the proposal: `AudioResponse` carries no usage, and the token-based pricing model cannot represent per-character or per-minute billing without a second dimension. `TranscriptionResponse` *does* carry a `Usage`, but tracking half of audio spend is worse than tracking none — it produces a number that looks authoritative and is wrong.

## Service surface

```php
namespace Jvjvjv\CodeTalker\Services\Audio;

class SpeechService
{
    public function forSystem(
        AiSystem $system,
        string $text,
        ?string $voice = null,
        ?string $instructions = null,
    ): AudioResponse;

    public function forFeature(string $feature, string $text, ...): AudioResponse;
}

class TranscriptionService
{
    public function forSystem(
        AiSystem $system,
        TranscribableAudio|UploadedFile $audio,
        ?string $language = null,
        bool $diarize = false,
    ): TranscriptionResponse;

    public function forFeature(string $feature, TranscribableAudio|UploadedFile $audio, ...): TranscriptionResponse;
}
```

Both return laravel/ai's own response objects rather than package-specific DTOs. Wrapping them would add a mapping layer with no behavior, and would hide `TranscriptionResponse`'s diarized `segments` — the most useful thing it carries — behind a type this package would then have to maintain.

`$system->model` is passed straight through and may be null, in which case laravel/ai falls back to the provider default. A record can therefore be created with a provider and a key and nothing else.

## Testing approach

`Transcription::fake()` and `Audio::fake()` are shipped upstream with `assertGenerated` / `assertNotGenerated` / `assertQueued` helpers, so no HTTP faking is needed.

The cases that matter — each one a thing that would otherwise break silently:

- The provider/role validation matrix, table-driven over all 21 pairs.
- The configurator emits `driver => eleven` for an ElevenLabs record. A wrong driver key fails at resolution with an unhelpful message.
- Both services reject a wrong-role record and an inactive record.
- A blank-model record reaches the gateway with a null model, so the provider default applies.
- `AgentFactory::systemForFeature()` still resolves chat records and now rejects audio records — proving the extraction preserved behavior.
- Existing `AiSystem` records default to `role = chat` after migration, so the chat path is untouched.

## Why

`laravel/ai ^0.9` — already a hard dependency of this package — ships complete speech-to-text and text-to-speech support that code-talker does not expose. `Laravel\Ai\Transcription` and `Laravel\Ai\Audio` are fully implemented facades with provider gateways, fakes, and queued variants. Nothing in this package can reach them.

Reaching them is not a matter of calling the facade. Both resolve their provider and credentials from `config('ai.providers.*')`, which this package deliberately never populates statically — `AiSystemProviderConfigurator` injects a `code-talker-system-{id}` entry per `AiSystem` at call time so credentials live on encrypted DB records instead of the host's env. A host calling `Audio::of($text)->generate()` today would bypass that entirely and read `AI_OPENAI_API_KEY`, configuring OpenAI twice in two unrelated places.

There is a second, sharper reason. A chat system and an audio system **cannot be the same record**. `openai-compatible` — the driver serving both `lm-studio` and `openai-compatible` — implements neither `AudioProvider` nor `TranscriptionProvider`. Any deployment chatting on a local model must resolve audio to a different provider than chat, always. So this is not a column added to an existing record; it is a second kind of record.

## What Changes

- **`AiSystem` gains a `role`** — `chat` (default), `audio`, or `transcription` — via a new `Enums\AiSystemRole` and a string column mirroring how `provider` is already modeled. Existing rows need no backfill.
- **`AiProvider` gains `ElevenLabs`**, mapping to laravel/ai's `eleven` driver, plus a `supportsRole()` method encoding which providers actually implement which gateway. ElevenLabs is audio-only; `anthropic`, `grok`, `lm-studio`, and `openai-compatible` are chat-only.
- **Two new services** under `Services/Audio/` — `SpeechService` and `TranscriptionService` — mirroring `AgentFactory`'s `forSystem()` / `forFeature()` shape, resolving credentials through the existing configurator, and wrapping calls in `RawExchangeRecorder::capture()`.
- **Form-request validation** rejects provider/role pairs that cannot work, turning a runtime `InvalidArgumentException` thrown from inside a gateway into a form error.
- **Admin CRUD** gains a role selector and a role column, and stops offering chat-only providers when the role is audio.

**Not** in scope, deliberately: `AiLlmMessage` logging, usage/cost tracking, and any change to the chat turn. See **Impact** and `design.md` for why each is cut.

## Capabilities

### New Capabilities

- `audio-systems`: configuring and invoking speech-to-text and text-to-speech providers through `AiSystem` records, with the same credential resolution, activation guards, and feature-default mapping the chat path already uses.

### Modified Capabilities

The streaming chat turn is untouched — `CodeTalkerAgent`, `ConversationTurnRunner`, and `StreamTranslator` are not modified.

One existing behavior does change: `AgentFactory::systemForFeature()` keeps its public signature, return type, and existing error messages, but its body moves to a shared resolver and it now additionally rejects a feature default that maps to an audio or transcription record. This tightens a gap that exists today — nothing currently stops a chat feature from resolving to a record repurposed for another role — and cannot affect any host whose feature defaults all point at chat systems, which is every host before this change, since no other role exists yet.

## Impact

- **Code**: `Enums/AiProvider`, new `Enums/AiSystemRole`, `Models/AiSystem`, `Services/LaravelAi/AiSystemProviderConfigurator` (one URL fallback), new `Services/Audio/`, new `Services/AiSystemResolver`, both `AiSystem` form requests, `Admin/AiSystemController`, one migration, `config/code-talker.php`.
- **Version**: additive — a minor bump. The `role` column defaults to `chat`, so every existing record and every existing call path behaves identically.
- **Deferred by design**: cost tracking. `AudioResponse` carries no usage data at all, TTS is billed per character, and STT is billed per minute — while the whole of `ConversationUsageService` is built on `input_per_million` / `output_per_million` *tokens*. Adding audio to it means either wrong numbers or a second pricing dimension, and neither earns its place before a voice feature exists to generate volume.
- **Known gap, not a defect**: `raw_exchanges.providers` defaults to `lm-studio`, so audio exchanges are captured only if a host adds `openai` / `elevenlabs` to the allow-list or sets `all`. This is the documented behavior of that setting and should be documented for audio rather than special-cased.
- **Sequencing**: this is change 2 of 3 in a multimodal arc. Change 1 (message attachments and vision through the chat turn) is independent. Change 3 (voice conversations — mic to STT to turn to TTS frames on the SSE stream) depends on both, and is where `AiLlmMessage` logging and usage tracking for audio become worth building, because there is finally a conversation to attach them to.

# Raw Provider Exchange Logging — Design

**Date:** 2026-07-19
**Status:** Approved (design), pending implementation plan
**Package:** `jvjvjv/code-talker`

## Problem

Provider communication runs entirely through the first-party `laravel/ai` SDK.
Its gateway drivers read each HTTP response, normalize it into `StreamEvent` /
`StepResponse` objects, and **discard the raw provider bytes**. By the time
`AiChatBotConversationService` iterates `$agent->stream()`, the literal wire
bytes are gone.

Consequences observed:

- The `ai_llm_messages.raw_response` column (added in migration
  `2026_05_25_143806`) is no longer populated by anything — it is a dead column
  left over from the pre-`laravel/ai` per-provider services (`ExecutesAiTools`).
- Non-chat laravel/ai calls — notably memory extraction in `AiMemoryService`
  (`$agent->prompt()`), which produces the `{add, update, remove}` JSON — create
  **no database record at all**. They are visible only in the provider's own
  server log (e.g. LM Studio's `Generated prediction:` output), and their token
  usage is never tracked.

## Goal

Log **every byte** of every request and response for laravel/ai HTTP calls
(streaming chat turns and non-streaming calls alike), for LM Studio today and
any provider in the future, into a dedicated table — without changing the chat
or memory logic and without ever breaking a chat turn if logging fails.

The `StreamTranslator` browser wire format is a compatibility surface and is
**not** touched by this work.

## Scope decisions (from brainstorming)

- **Fidelity:** literal wire bytes, captured by tee-ing the response stream (not
  a reconstruction from normalized events).
- **Storage:** a new dedicated table, `ai_provider_exchanges` — *not* the
  conversation-scoped `ai_llm_messages` table (whose `ai_conversation_id` /
  `turn_number` are required and would be distorted by context-less calls).
- **Engine:** provider-agnostic. The capture seam sits below any provider
  distinction, so building it for "all providers" is the same work as building
  it for LM Studio alone.
- **Default scope:** provider allow-list, defaulting to `lm-studio`
  (env-overridable), because the seam is global and we only want AI provider
  traffic — not host-app HTTP.
- **Retention:** prune enabled; default 14 days (env-overridable), with a
  scheduled daily prune command.
- **Secrets:** API keys live in the `Authorization` header, which is never read
  or stored. Only JSON request bodies and raw response bodies are persisted.

## Why the `Http` global-middleware seam

Every `laravel/ai` gateway builds its client with Laravel's `Http` facade
(e.g. `CreatesOpenAiCompatibleClient::client()` →
`Http::baseUrl(...)->post('chat/completions', ...)`). `laravel/ai` exposes:

- Events (`AgentPrompted`, `AgentStreamed`) — carry **normalized** responses,
  not raw bytes. Rejected: cannot yield literal wire bytes.
- No gateway-level client middleware hook.

Laravel's `Http::globalResponseMiddleware()` (Guzzle response middleware) is the
only place to intercept the real response before its body is consumed. It is
app-global, so capture is scoped by (a) an active capture-context frame and
(b) a request-host match against the frame's provider base URL.

## Data model

New table `ai_provider_exchanges` — one row per captured laravel/ai HTTP call.

| Column | Type | Notes |
|---|---|---|
| `id` | pk | |
| `provider` | string | e.g. `lm-studio`; from the `AiSystem`, not the driver (LM Studio and OpenAI share the `openai-compatible` driver) |
| `endpoint` | string | request path, e.g. `/v1/chat/completions` |
| `method` | string | e.g. `POST` |
| `streaming` | boolean | whether the response was an SSE stream |
| `http_status` | unsignedInteger, nullable | response status code |
| `request_body` | longText, nullable | verbatim request body (JSON); no headers |
| `raw_response` | longText, nullable | **every response byte**: full JSON body or concatenated SSE stream |
| `model` | string, nullable | resolved model |
| `duration_ms` | unsignedInteger, nullable | |
| `ai_system_id` | foreignId, nullable, nullOnDelete | correlation |
| `ai_conversation_id` | foreignId, nullable, nullOnDelete | correlation |
| `ai_llm_message_id` | foreignId, nullable, nullOnDelete | correlation |
| `created_at` | timestamp, useCurrent, indexed | index supports pruning |

Model `AiProviderExchange`:

- `raw_response` and `request_body` are **plain text** (not `array`-cast) — a
  streamed body is concatenated SSE, not a single JSON document.
- Only `created_at` is managed (no `updated_at`).
- `$fillable` covers all columns above; nullable FKs guarded.

The existing dead `ai_llm_messages.raw_response` column is left as-is by this
work (out of scope to remove; it is simply unused).

## Components

### `RawExchangeContext` (scoped singleton)
Holds a **stack** of capture frames. Each frame:
`{ provider, base_url, ai_system_id, ai_conversation_id, ai_llm_message_id, model }`.

- `push(frame)`, `pop()`, `current(): ?frame`.
- Registered as a singleton so the global middleware and the calling service
  share the same instance within a request.
- A `run(frame, callable)` convenience that push/pops around a closure
  (finally-guaranteed pop).

### `TeeingStream` (PSR-7 `StreamInterface` decorator)
Wraps the real response body. Every `read()` passes through to the inner stream
**and** appends the returned bytes to an internal buffer. Flushes the buffer to
its `onFlush(string $bytes)` callback **exactly once**, guarded by a boolean,
triggered on whichever comes first:

- inner stream reaches EOF, or
- `close()` / `__destruct()`.

Both triggers are needed: the SSE parser `return`s at `[DONE]` and may not read
the inner stream to true EOF, so close/destruct is the backstop.

### `RawExchangeRecorder`
Registered once in `CodeTalkerServiceProvider::boot()` via
`Http::globalResponseMiddleware()`. For each response it evaluates capture
predicate:

1. `config('code-talker.raw_exchanges.enabled')` is true, **and**
2. `RawExchangeContext::current()` is non-null, **and**
3. the frame's `provider` is in the configured allow-list
   (`providers === 'all'` or the provider is listed), **and**
4. the request host matches the frame's `base_url` host — this keeps out
   unrelated HTTP made by tool handlers during the agentic loop.

If the predicate passes, it wraps the response body in a `TeeingStream` whose
`onFlush` writes one `AiProviderExchange` row (provider, endpoint, method,
streaming flag, status, request body, raw bytes, model, duration, correlation
ids from the frame). If it fails, the original response is returned untouched.

The whole record path is wrapped so a logging failure never propagates:
`try { ... } catch (\Throwable $e) { Log::warning(...); }` and the original
response flows on.

Whether a response is `streaming` is inferred from the request options
(`stream => true`) or the response content type.

### `PruneProviderExchangesCommand` (`ai:prune-provider-exchanges`)
Deletes `ai_provider_exchanges` rows with
`created_at < now()->subDays(retention_days)`. Auto-scheduled **daily**,
alongside the package's existing scheduled jobs, and skipped when
`config('code-talker.schedule') === false`.

## Data flow & correlation

The package's own services are the only laravel/ai call sites, so they open the
capture frame:

1. **`AiChatBotConversationService`** — wraps its `$agent->stream()` loop in a
   frame carrying `ai_system_id`, `ai_conversation_id`, and the id of the
   response `AiLlmMessage` it creates for that attempt. Resulting exchange row
   is fully correlated. (For continuation attempts, each attempt opens its own
   frame with that attempt's message id.)
2. **`AiMemoryService`** and any other `AgentFactory`-based call — open a frame
   with `ai_system_id` + `provider` + `base_url` (+ `model`) only.
   Conversation/message links are null; the exchange is still logged, closing
   the current "invisible memory call" gap.
3. **Host-app HTTP** and **tool-handler HTTP** during the agentic loop — no
   active frame, or host mismatch → never captured.

Config decision, per brainstorming: context is opened at the **service call
sites**, not deeper inside the agent, so correlation ids (conversation, message)
that only the service knows are available to the frame.

The `base_url` for a frame is the provider base URL resolved for the
`AiSystem` (the same value `AiSystemProviderConfigurator` uses); the host of
that URL is what the predicate's host-match check compares against.

## Configuration

```php
// config/code-talker.php
'raw_exchanges' => [
    'enabled'        => env('CODE_TALKER_RAW_EXCHANGES_ENABLED', true),
    'providers'      => env('CODE_TALKER_RAW_EXCHANGES_PROVIDERS', 'lm-studio'), // 'all' or comma-list
    'retention_days' => env('CODE_TALKER_RAW_EXCHANGES_RETENTION_DAYS', 14),
],
```

- `providers` parsed into a normalized array; the literal string `all` (or an
  empty value meaning "all") disables the allow-list filter.
- Values are `AiProvider` enum values (e.g. `lm-studio`, `anthropic`,
  `openai`), matched against the `AiSystem` provider — **not** the laravel/ai
  driver name.

## Error handling & safety

- Recorder never throws into the request path; logging failures are caught and
  warned, original response returned.
- `Authorization` / `api-key` headers are never read or stored.
- Response/request bodies stored as `longText` (MySQL ~4 GB; SQLite unbounded).
  Retention prune is the size pressure valve.
- Flush-once guard on `TeeingStream` prevents duplicate rows when both EOF and
  close fire.

## Testing

- **`TeeingStream` unit:** passthrough read integrity (decorated reads equal
  the raw source) and single flush — triggered on EOF and, separately, on
  `close()` without reaching EOF.
- **Streaming feature:** an lm-studio SSE response body → exactly one
  `ai_provider_exchanges` row, `streaming = true`, `raw_response` equal to the
  verbatim concatenated stream, correlation ids populated. (May require a
  hand-built streamed PSR-7 body — see risk below.)
- **Non-streaming feature:** a memory-style `chat/completions` response → one
  row with the full JSON body and null conversation/message correlation.
- **Allow-list:** a provider not in the configured list → **no** row.
- **Disabled flag:** `enabled = false` → no rows even with an active frame.
- **Prune:** `ai:prune-provider-exchanges` deletes only rows older than
  `retention_days`; newer rows survive.

## Risks / open implementation notes

- **`Http::fake()` fidelity:** faked responses may not exercise the real Guzzle
  streaming/tee path. The streaming test likely needs a hand-constructed
  streamed PSR-7 body rather than a plain fake. Resolve during implementation.
- **Global middleware reach:** `Http::globalResponseMiddleware` affects all
  `Http` facade usage in the host app; the frame + host-match predicate is what
  confines capture to AI provider traffic. Confirmed acceptable in design.

## Out of scope

- Removing or repurposing the legacy `ai_llm_messages.raw_response` column.
- Tracking token usage/billing for memory-extraction calls (separate gap noted
  during discussion; not part of this work).
- Changing the `StreamTranslator` browser wire format.

## Documentation to update on ship

- `config/code-talker.php` publish + README "Configuration" section: document
  the `raw_exchanges` block and its env vars.
- `CHANGELOG.md`: New Features entry for raw provider exchange logging + the
  prune command, on release.

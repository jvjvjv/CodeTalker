# code-talker

Multi-provider AI communications package for Laravel — conversation storage, streaming turns, tool-use, memory, and management services. No routes, no UI.

## Requirements

- PHP ^8.3
- Laravel ^12.62 || ^13.15

Provider communication runs on Laravel's first-party [laravel/ai](https://laravel.com/docs/13.x/ai-sdk)
SDK, installed as a dependency. You do **not** need to publish or configure
`config/ai.php` — provider credentials come from `AiSystem` database records and
are bridged into laravel/ai providers at runtime. (Publish it only if your app
uses laravel/ai on its own.)

## Installation

```bash
composer require jvjvjv/code-talker
```

Publish the config and migrations, then run them:

```bash
php artisan vendor:publish --tag=code-talker-config
php artisan vendor:publish --tag=code-talker-migrations
php artisan migrate
```

## Upgrading

After upgrading the package, **re-publish or reconcile your published config**:

```bash
php artisan vendor:publish --tag=code-talker-config --force
```

This matters because the package merges its config **shallowly** (Laravel's
`mergeConfigFrom`). If your app already has a published `config/code-talker.php`
with a `providers` key, newer or corrected **nested** keys the package ships —
most notably `providers.*.base_url`, the `raw_exchanges` block, and the
`conversations` block — are **not** backfilled into it. Your previously published array is used as-is, so a stale
publish can silently keep an outdated provider base URL (see
[Troubleshooting](#troubleshooting)).

After `--force`, re-apply any local customizations. If you'd rather not
overwrite, diff your file against `vendor/jvjvjv/code-talker/config/code-talker.php`
and copy over only the new keys.

### Migrating between versions

Every release's full details live in [`CHANGELOG.md`](CHANGELOG.md); this table
is the fast path — what you actually have to *do* to move to a given version,
skipping straight to it if you're jumping several releases at once. Apply every
row from your current version up to your target, in order.

| Version | Action required |
| ------- | ---------------- |
| **0.14.0** | **Breaking.** `AiChatBot` → `AiPersona` throughout.<br>• Re-publish and run migrations (renames `ai_chat_bots`/`ai_chat_bot_id`, adds `ai_operators`/`ai_operator_id`).<br>• Update code referencing `AiChatBot`, `AiChatBotManager`, `AiChatBotConversationService` to the `AiPersona*` names (same shapes/constructors).<br>• Update stored `prompt_template` placeholders: `{{bot_name}}`/`{{bot_slug}}`/`{{bot_description}}` → `{{persona_name}}`/`{{persona_slug}}`/`{{persona_description}}`.<br>• Update any `feature_keys` config entries from `chat-bot:*` to `persona:*` (stored `AiSystemFeatureDefault` rows are migrated automatically; your config array is not).<br>• Rename a host-provided `AiChatBotFactory` to `AiPersonaFactory`. |
| **0.13.0** | None — `composer update` only. Optionally re-publish `code-talker-types`/`code-talker-client` to get `onPageReload`/`onToolProgress`. |
| **0.12.1** | None — `composer update` only. |
| **0.12.0** | **Breaking** if you call `http-request` with a `headers` object. Change it to a line-based string: `"Name: value"`, one per line. Everything else in this release is additive. |
| **0.11.0** | **Breaking, large.** Read the full entry before upgrading.<br>• All package routes/controllers are gone — build your own endpoint against `AiChatBotConversationService` (now `AiPersonaConversationService` as of 0.14.0) and `Services/Management/`.<br>• Re-publish and run migrations (`..._add_message_structure_to_ai_conversation_messages_table`).<br>• Replace session/cookie conversation resolution with `AiConversation::findByChatHashOrUuid()`.<br>• Supply `usingCancellationCheck()` for any turn driven outside an HTTP request.<br>• If you render the chat pages, install and configure Inertia yourself — the package's `inertia` config block and dependency are gone.<br>• `fetch-web-page` now refuses private/loopback hosts by default; add `request_policy.allow_private_hosts` where you relied on reaching internal services. |
| **0.10.0** | **Breaking only if you subclass `ChatBotController`.** Its constructor signature and several `protected` helpers changed/were removed. Prefer binding a replacement collaborator under `Services/ChatBot/` over subclassing. |
| **0.9.2** | None — `composer update` only. |
| **0.9.1** | None — `composer update` only. |
| **0.9.0** | None required. Optional: switch any error-text pattern-matching to the new `reason` code (`max_stream_duration`/`provider_error`). |
| **0.8.0** | None — `composer update` only. |
| **0.7.1** | Not breaking, but check your web server's request-header buffer size (e.g. nginx `large_client_header_buffers 8 32k;`) so browsers with the old bloated per-bot cookies can reach the app long enough to have them cleared. |
| **0.7.0** | **Breaking.** Memory extraction timing changed — it now fires once, up to ~45 minutes after a conversation goes idle, not after every message.<br>• Re-publish config for the new `conversations.idle_timeout_minutes` key.<br>• If you disabled the package scheduler (`'schedule' => false`), register `ai:complete-idle-conversations` yourself or memory extraction will never run. |
| **0.6.0** | **Breaking.** Requires PHP `^8.3` and Laravel `^12.62 \|\| ^13.15`.<br>• Replace `AiClientContract`/`ClaudeService`/etc. usage with `AgentFactory::forSystem()`/`forFeature()`.<br>• Remove the retired `anthropic-ai/sdk`, `openai-php/client`, `google-gemini-php/client` dependencies if referenced directly.<br>• If you parse `AiLlmMessage` rows, note tool-use iterations no longer log as separate request/response rows. |
| **0.5.0** | **Breaking.** Tools are now `laravel/mcp` `Tool` classes (`AiToolHandlerContract` still works for one release).<br>• Run the published migration remapping `AiSystem::allowed_tools` from snake_case to kebab-case tool names.<br>• `composer update` pulls in the new `laravel/mcp` dependency automatically. |
| **0.4.1** | None — `composer update` only. |
| **0.4.0** | None — `composer update` only. |
| **0.3.0** | None — `composer update` only. |
| **0.2.4** | None — `composer update` only. |
| **0.2.3** | None required. Optional: `php artisan vendor:publish --tag=code-talker-routes` if you want to customize the route files. |
| **0.2.2** | If you relied on the default system prompt seed to create `TargetedResumeService`-specific prompts, create those manually or via your own migration — the seed no longer includes them. |
| **0.2.1** | None generally. Affects only hosts that had worked around the prior hardcoded-UUID migration bug themselves. |
| **0.2.0** | **Breaking.** Package-managed chat-bot visibility/roles are removed (`is_public`, `allowed_roles`).<br>• Run the migration dropping `ai_chat_bots.is_public`.<br>• Move all bot/admin access decisions into your own middleware, gates, or policies. |
| **0.1.2** | None — `composer update` only. |
| **0.1.1** | None — `composer update` only. |
| **0.1.0** | Initial release. |

## Configuration

`config/code-talker.php` controls package-wide behavior:

| Key                                  | Default                                  | Description                                                      |
| ------------------------------------ | ---------------------------------------- | ---------------------------------------------------------------- |
| `user_model`                         | `App\Models\User::class`                 | Eloquent model used for authenticated users                      |
| `reserved_slugs`                     | `[]`                                     | Additional slugs that cannot be used for root-path personas      |
| `feature_keys`                       | `[]`                                     | Valid feature keys for system defaults; empty accepts any string |
| `schedule`                           | `true`                                   | Set to `false` to disable the package's automatic scheduled jobs |
| `conversations.idle_timeout_minutes` | `30`                                     | Inactivity before a conversation is marked `Completed`           |

### Suggested host-app packages

- `bspdx/keystone` is suggested if you want a ready-made host-app authorization layer around your own admin screens.

### Provider environment variables

API keys, models, and token limits live on `AiSystem` database records — not in
env vars. The env vars below only supply fallback base URLs (used when an
`AiSystem` has no `base_url`), the Anthropic API version, and the LM Studio
server URL:

```
ANTHROPIC_API_VERSION=2023-06-01
ANTHROPIC_BASE_URL=https://api.anthropic.com/v1
OPENAI_BASE_URL=https://api.openai.com/v1
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta
GROK_BASE_URL=https://api.x.ai/v1
LMSTUDIO_SERVER_URL=http://localhost:1234
```

The `providers.*.pricing` config keys feed conversation usage/cost tracking.

**Base URLs must include the API version path segment** — `/v1` for
`anthropic`, `openai`, and `grok`; `/v1beta` for `gemini` (the defaults above
already do). Provider communication treats the configured URL as the complete
base and appends the endpoint directly. This differs from the retired
`anthropic-ai/sdk`, which accepted a bare host (`https://api.anthropic.com`) and
appended `/v1` itself — so a bare host now produces 404s. This applies both to a
live `AiSystem.base_url` value and to these `providers.*.base_url` fallbacks. See
[Troubleshooting](#troubleshooting).

### Raw Provider Exchange Logging

Every laravel/ai HTTP request/response can be captured verbatim into the
`ai_provider_exchanges` table for debugging and auditing.

```php
'raw_exchanges' => [
    'enabled' => env('CODE_TALKER_RAW_EXCHANGES_ENABLED', true),
    'providers' => env('CODE_TALKER_RAW_EXCHANGES_PROVIDERS', 'lm-studio'),
    'retention_days' => (int) env('CODE_TALKER_RAW_EXCHANGES_RETENTION_DAYS', 14),
],
```

- `enabled` — master switch for capture.
- `providers` — comma-separated allow-list of `AiSystem` provider values
  (`lm-studio`, `anthropic`, `openai`, `openai-compatible`, `gemini`, `grok`),
  or `all` to capture every provider. Defaults to `lm-studio`.
- `retention_days` — rows older than this are removed by
  `php artisan ai:prune-provider-exchanges`, scheduled daily at 03:00 (respects
  the `schedule` flag).

Request bodies and response bytes are stored, but request **headers are never
recorded**, so provider API keys are not persisted.

### Detached Turns

A turn dispatched with `dispatchTurn()` runs as a queued job and writes its
events to the `ai_turn_events` table, so a browser reload resumes it instead of
killing it (see [Running a turn as a job](#running-a-turn-as-a-job)).

```php
'turns' => [
    'queue' => env('CODE_TALKER_TURN_QUEUE'),
    'abandon_after_seconds' => (int) env('CODE_TALKER_TURN_ABANDON_SECONDS', 30),
    'poll_interval_ms' => (int) env('CODE_TALKER_TURN_POLL_MS', 250),
    'max_stream_seconds' => (int) env('CODE_TALKER_TURN_MAX_STREAM_SECONDS', 900),
    'retention_days' => (int) env('CODE_TALKER_TURN_RETENTION_DAYS', 7),
],
```

- `queue` — the queue `RunConversationTurnJob` is dispatched on; `null` uses
  the default queue.
- `abandon_after_seconds` — a running turn stops when nobody has read its
  events for this long. `connection_aborted()` reports 0 in a worker, so this
  is what stops a turn nobody is waiting for.
- `poll_interval_ms` — how often a reader polls the store for new events.
- `max_stream_seconds` — ceiling for a single `resumeTurn()` read before it
  ends with a `max_stream_duration` error; reconnecting starts a fresh window.
- `retention_days` — finished runs older than this are removed by
  `php artisan ai:prune-turn-events`, scheduled daily at 03:15 (respects the
  `schedule` flag).

Note that `turns.max_stream_seconds` bounds only the read side. Generation
inside the worker is governed by `conversations.max_stream_seconds` (default
300), which caps each individual provider request — the same guard the
synchronous path applies, enforced promptly during provider silence by the
heartbeat rather than only when the next provider event arrives. A host running
a large-context local model, where prompt processing alone can occupy minutes
of a single request, should raise `conversations.max_stream_seconds`
accordingly.

### Troubleshooting

**`Provider is unavailable: HTTP request returned status code 404`** — returned
by the model-status / readiness check (and cloud-provider chat also 404s) for
`anthropic`, `openai`, `gemini`, or `grok`.

- **Cause:** the provider base URL is missing its version segment — e.g.
  `https://api.anthropic.com` instead of `https://api.anthropic.com/v1`. This is
  usually a **stale published config** (the shallow merge described in
  [Upgrading](#upgrading) never backfilled the corrected default) or an old
  `AiSystem.base_url` / `ANTHROPIC_BASE_URL` value carried over from the
  `anthropic-ai/sdk` era.
- **Fix:** set the `AiSystem.base_url` to include `/v1` (or clear it to fall back
  to the config default), fix any bare-host `*_BASE_URL` env var, and re-publish
  the config with `--force` (see [Upgrading](#upgrading)). The correct URL forms
  are listed under [Provider environment variables](#provider-environment-variables).

## AI Systems

An `AiSystem` record represents a fully configured provider endpoint. Create one with `AiSystemManager` (see **Management Services**) or via a seeder. Key fields:

| Field              | Description                                                                       |
| ------------------ | --------------------------------------------------------------------------------- |
| `provider`         | One of: `anthropic`, `openai`, `openai-compatible`, `gemini`, `grok`, `lm-studio` |
| `model`            | Provider-specific model name                                                      |
| `api_key`          | Stored encrypted                                                                  |
| `max_tokens`       | Maximum output tokens per request                                                 |
| `temperature`      | Sampling temperature (overrides persona-level default)                            |
| `context_length`   | Context window for local models (LM Studio)                                       |
| `enable_thinking`  | Enable extended thinking / reasoning output (Anthropic)                           |
| `allowed_tools`    | Array of tool names the model may invoke                                          |
| `web_tool_policy`  | Domain allow-list and credentials for `fetch-web-page`/`http-request` — see below  |
| `system_prompt_id` | Optional FK to an `AiSystemPrompt` record                                         |
| `is_active`        | Inactive systems are rejected by the factory                                      |

### Getting an agent in code

`AgentFactory` bridges an `AiSystem` record into a configured
[laravel/ai](https://laravel.com/docs/13.x/ai-sdk) agent:

```php
use Jvjvjv\CodeTalker\Services\LaravelAi\AgentFactory;
use Jvjvjv\CodeTalker\Models\AiSystem;

// From a specific system record
$agent = app(AgentFactory::class)->forSystem(
    AiSystem::find($id),
    instructions: 'You are a helpful assistant.',
    maxTokens: 2048,
    temperature: 0.7,
);

// From a feature key (resolves the default system for that feature)
$agent = app(AgentFactory::class)->forFeature('my-feature');

$response = $agent->prompt('Hello!');   // Laravel\Ai\Responses\AgentResponse
echo $response->text;

foreach ($agent->stream('Hello!') as $event) {
    // Laravel\Ai\Streaming\Events\* (TextDelta, ToolCall, StreamEnd, ...)
}
```

The agent runs on laravel/ai, so everything from the
[laravel/ai documentation](https://laravel.com/docs/13.x/ai-sdk) — streaming,
tool use, structured output — applies. Prior versions returned an
`AiClientContract` from `AiClientFactory`; both were removed in 0.6.0.

### Feature defaults

Map a feature key to a default `AiSystem` via the `ai_system_feature_defaults` table (managed through `AiSystemManager`). This decouples application code from specific system IDs.

## Conversation History

The package implements `Laravel\Ai\Contracts\ConversationStore` over its own
tables and binds it over the framework default, so an agent resumed onto a
conversation replays Code Talker's history — including tool calls, tool results,
and attachments, none of which a transcript rebuilt from message text can carry.

```php
$agent = $factory->forSystem($system)->continue((string) $conversation->id, $user);
```

Conversations must already exist. `storeConversation()` throws, because a Code
Talker conversation requires an `AiSystem` that the contract gives no way to
supply — open one with `AiPersonaConversationService::startConversation()` first.

### Two writers

`continue()` attaches a conversation participant, which arms laravel/ai's
remembering middleware. That middleware persists both messages of a turn. If you
*also* drive `AiPersonaConversationService`, every turn is written twice.

The package's own chat flow avoids this by resuming without a participant:

```php
$agent->withStoredConversation((string) $conversation->id);
```

That replays history but leaves the middleware disarmed, so `TurnRecorder`
remains the only writer. This is deliberate rather than incidental: the
middleware persists from a callback that fires only once the stream is fully
consumed, and a turn cut short by a client disconnect or the duration guard never
gets there — so the middleware would silently discard partial output that
`TurnRecorder` keeps.

If you use `continue()` directly, also set `ai.conversations.generate_title` to
`false` unless you want a second provider call per new conversation for a title
the package already derives locally.

## Personas

An `AiPersona` defines a user-facing, turn-driven character — one that responds
when a human sends it a message. (For AI work dispatched independently of a
human message, see [Operators](#operators).) Create one with `AiPersonaManager`. Key fields:

| Field                      | Description                                          |
| -------------------------- | ---------------------------------------------------- |
| `ai_system_id`             | The backing `AiSystem`                               |
| `name`                     | Display name                                         |
| `slug`                     | URL-safe identifier, must be unique                  |
| `access_path`              | `chat` → `/chat/{slug}`, `root` → `/{slug}`          |
| `prompt_template`          | System prompt with optional placeholders (see below) |
| `require_visitor_identity` | Prompt anonymous visitors for name and email         |
| `tools_enabled`            | Whether the persona may invoke registered tools      |
| `temperature`              | Overrides `AiSystem` temperature for this persona    |

Persona authentication and authorization are not managed by this package. The
consuming application must decide which users or guests can reach persona
routes by applying its own middleware, gates, or policies around the package
routes.

### Prompt template placeholders

These tokens are replaced when a conversation starts:

| Placeholder                | Value                                           |
| --------------------------- | ----------------------------------------------- |
| `{{persona_name}}`        | Persona's display name                          |
| `{{persona_slug}}`        | Persona's slug                                  |
| `{{persona_description}}` | Persona's description field                     |
| `{{visitor_name}}`    | Name collected from anonymous visitor (if any)  |
| `{{visitor_email}}`   | Email collected from anonymous visitor (if any) |

The final system prompt is assembled as: `AiSystemPrompt.content` + prompt template + `## Learned Insights` (injected memories).

### Driving a turn

The package registers no routes and renders no pages. You write the endpoint;
the package supplies the turn.

```php
use Jvjvjv\CodeTalker\Services\AiPersonaConversationService;
use Jvjvjv\CodeTalker\Services\ChatBot\SseFrameEncoder;

public function message(Request $request, AiPersona $persona,
    AiPersonaConversationService $chat, SseFrameEncoder $encoder)
{
    $validated = $request->validate(['message' => ['required', 'string']]);

    $conversation = $this->resolveConversation($request, $persona)
        ?? $chat->startConversation($persona, $request->user());

    return response()->stream(function () use ($chat, $conversation, $validated, $encoder) {
        // PHP only reports a dead connection once output has been flushed to
        // it, so keep the script alive past an abort and flush every frame.
        ignore_user_abort(true);

        foreach ($encoder->encode($chat->continueConversation($conversation, $validated['message'])) as $frame) {
            echo $frame;
            ob_get_level() > 0 && ob_flush();
            flush();
        }
    }, headers: [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'X-Accel-Buffering' => 'no',
        'X-Chat-Hash' => $conversation->chat_hash,
    ]);
}
```

`continueConversation()` yields **structured events**, not wire-encoded strings.
`SseFrameEncoder` turns them into the documented server-sent-event framing; skip
it and deliver them over a websocket, a broadcast channel, or anything else.

### Turn events

Every event carries a `type`. These are typed in the published declarations.

| Type                    | Payload                                                     |
| ----------------------- | ----------------------------------------------------------- |
| `status`                | `phase` (`model_loading`), `message`                        |
| `message_start`         | —                                                            |
| `content_block_delta`   | `delta.text`                                                 |
| `reasoning_block_delta` | `delta.reasoning`                                            |
| `message_delta`         | `delta.stop_reason` (`end_turn`/`max_tokens`/`tool_use`/`incomplete`), `usage` |
| `message_stop`          | —                                                            |
| `heartbeat`             | — (encoded as an SSE comment, not a data frame)              |
| `tool_use_progress`     | `text` (always `""`), `tools` (one tool name per event), plus `input`/`output`/`successful` when tool payloads are enabled |
| `page_reload`           | —                                                             |
| `error`                 | `message`, `reason` (`max_stream_duration`/`provider_error`) |

`tool_use_progress` fires once per tool call the model makes mid-turn — the raw
provider `ToolCall`/`ToolResult` events are never forwarded (their payloads
aren't display text), so without this a turn calling a tool, especially one
retrying after an error, streams nothing but silence between text/reasoning
deltas.

`stop_reason` is `incomplete` when the turn never finished — the connection
dropped, or the server's duration guard cut the generation off. Whatever
content arrived stops mid-answer, and the turn is stored that way (see
[Interrupted turns](#interrupted-turns)).

`heartbeat` fires while the provider is silent. `SseFrameEncoder` renders it as
`: ping` — an SSE comment — so browsers and the published client ignore it
without any handling. It is there so something reaches the socket during a long
gap: intermediaries stop timing out mid-answer, and PHP only flips
`connection_aborted()` after a write to a dead connection, so without it an
abandoned turn keeps generating until the model's next event. Set
`conversations.heartbeat_seconds` to `0` to disable. Detection costs two beats:
the first write marks the socket dead, the second observes it.

`page_reload` fires when a tool's structured result carries `_page_reload:
true` — see [Tool Registration](#tool-registration) for how a tool sets it.
Deciding what "reload" means (call `location.reload()` immediately, wait for
the turn to finish, debounce repeated signals) is left to the host; the
package only reports that a tool changed server state.

Encoded, each becomes `data: <json>\n\n`, and a turn that **finished** ends with
`data: [DONE]\n\n`. An `error` event is terminal on its own and is *not*
followed by the sentinel — that asymmetry is how a consumer tells a failed turn
from a completed one.

### Cancellation

The turn stops early when its cancellation check fires, and whatever it produced
is still persisted. The default suits a web request:

```php
// Default: stops when the browser hangs up.
$chat->continueConversation($conversation, $message);
```

Outside a request that default is useless — `connection_aborted()` reports 0 in
CLI and queue contexts, so the guard silently never fires. Supply your own:

```php
$chat->usingCancellationCheck(fn (): bool => $job->isReleased())
     ->continueConversation($conversation, $message);
```

### Interrupted turns

A turn that stops before the model finishes — the browser hung up, or the
duration guard tripped — is still recorded, whatever it had produced:

- The assistant message is persisted even when it holds no text at all, so a
  user's question is never left with nothing beneath it. Its `metadata` carries
  `incomplete: true` and an `incomplete_reason` of `client_aborted` or
  `max_stream_duration`, and `ChatBotPresenter::transcript()` surfaces the flag
  as `incomplete` on the row. Render it as an interrupted reply rather than as
  an answer.
- Tool calls the model made before the stop are persisted with it. A tool that
  ran changed state on your side; dropping the turn would leave the next turn's
  history with no record it ever happened.
- `AiInteractionLog::status` is `aborted` (not `success`) for a turn the caller
  hung up on, with `provider_metadata.error_reason` set to `client_aborted`.
  The tokens it burned still count towards the conversation's usage totals —
  hanging up does not refund what the provider already generated.

### Running a turn as a job

`continueConversation()` ties the turn to the caller's connection: close the
tab and the turn stops, reload and it is gone. For turns long enough that this
matters, dispatch the turn instead and stream it from its store.

```php
// Start it. Returns an AiTurnRun; `public_id` is the handle to put in a URL.
$run = $chat->dispatchTurn($conversation, $request->string('message')->toString());

// Stream it — from the start, or from wherever the browser left off.
foreach ($encoder->encode($chat->resumeTurn($run, $after)) as $frame) {
    echo $frame;
    ob_get_level() > 0 && ob_flush();
    flush();
}

// Stop it early.
$chat->cancelTurn($run);
```

Each event is framed with an SSE `id:` carrying its sequence. A browser that
reconnects passes the last sequence it saw back as `after`, and the turn
resumes rather than replaying. The published client reports it via
`onSequence`.

A dispatched turn needs a queue worker. Because `connection_aborted()` reports
0 in a worker, a run stops when nobody has read it for
`turns.abandon_after_seconds` (default 30) — so closing the tab still stops
generation, and a reload inside that window reattaches to the same run.
`ai:prune-turn-events` clears finished runs past `turns.retention_days`.

### Resolving conversations across requests

The package used to keep this in the session and a cookie. It no longer does —
your endpoint decides. `AiConversation::findByChatHashOrUuid()` is the lookup,
and `$conversation->chat_hash` is a stable shareable handle that
`continueConversation()` keeps current.

### Presentation queries

`ChatBotPresenter` keeps the queries a chat UI needs:

```php
$presenter->transcript($conversation);              // visible messages, system prompt excluded
                                                    // each row carries `incomplete` — see Interrupted turns
$presenter->totalCostUsd($persona);                 // lifetime cost for a persona
$presenter->conversationsFor($user, $personas);     // an authenticated user's conversations
```

Readiness and warm-up are unchanged and were always transport-free:
`AiModelReadinessService` and `ChatBotStatusResolver`.

### Publishing types and a stream client

```bash
# TypeScript declarations for the turn events and transcript shape.
# Safe to re-publish on upgrade — these track the package.
php artisan vendor:publish --tag=code-talker-types

# A dependency-free stream client. Copied into your app and yours to edit
# from that point on; upgrades will not re-publish over your changes.
php artisan vendor:publish --tag=code-talker-client
```

The client POSTs a message and parses the encoded stream into typed callbacks
(`onText`, `onReasoning`, `onDone`, `onError`, …) with an abort handle. It works
against any endpoint that emits the framing above.

## Operators

An `AiOperator` is a persona-shaped config for bounded, single-shot AI work that
isn't triggered by a human sending a message — a host observer reacting to a
domain event, a scheduled sweep, anything that isn't a chat turn. Create one
with `AiOperatorManager`. Key fields:

| Field              | Description                                                    |
| ------------------ | ---------------------------------------------------------------|
| `ai_system_id`     | The backing `AiSystem`                                         |
| `name`             | Display name                                                   |
| `slug`             | URL-safe identifier, must be unique                             |
| `prompt_template`  | Prompt with `{{dotted.path}}` placeholders (see below)         |
| `allowed_tools`    | Tool names this operator may invoke (falls back to the `AiSystem`'s `allowed_tools` when null) |
| `is_active`        | Whether the operator can be dispatched                         |

**The package owns no trigger, event bus, or scheduling system for operators.**
Dispatching one is a single job, the same shape the package already uses
internally for post-conversation memory extraction:

```php
use Jvjvjv\CodeTalker\Jobs\RunAiOperatorJob;

// From anywhere that knows when this should run — an Eloquent observer, an
// event listener, a console command, your own scheduled job:
dispatch(new RunAiOperatorJob($operator, [
    'order' => $order->toArray(),
]));
```

A run is bounded: one interpolated prompt, laravel/ai's agentic tool loop
(the same step cap a chat turn uses), then done. There is no "keep going
until some goal is met" loop — an operator that stops on anything other than
a clean finish (e.g. the token limit) fails the job rather than continuing
silently, so it surfaces through your queue's normal failure handling.

### Prompt template placeholders

Unlike a persona's fixed placeholder set, an operator's placeholders are
arbitrary and resolve against whatever `$context` array the dispatching code
passed in, via dotted paths:

```php
// prompt_template: "A new order was placed: {{order.total}} for {{order.customer.email}}."
dispatch(new RunAiOperatorJob($operator, [
    'order' => ['total' => 42, 'customer' => ['email' => 'a@example.com']],
]));
```

A placeholder with no matching value in `$context` fails the run before any
provider call is made — a task prompt with a silently-blanked field is worse
than a loud failure.

### Audit trail and cost tracking

An operator run is recorded as an `AiConversation` (`feature` =
`operator:{slug}`, `ai_operator_id` set, `ai_persona_id` null), so it gets
`AiLlmMessage` request/response logging, raw exchange capture, and
`ConversationUsageService` cost rollups exactly the way a persona's turns do —
there is no operator-specific logging path.

## Tool Registration

Tools are [laravel/mcp](https://github.com/laravel/mcp) `Tool` classes. The same
class runs in the local chat loop **and** can be exposed to external MCP clients
(see [External MCP Server](#external-mcp-server)). Extend `Laravel\Mcp\Server\Tool`:

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get-weather')]
#[Description('Returns current weather for a given city.')]
class GetWeatherTool extends Tool
{
    public function __construct(
        private ToolContext $context,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->description('City name')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        // ... fetch weather ...
        return Response::structured(['temperature' => '72°F', 'condition' => 'Sunny']);
        // Use Response::error('...') to signal a failure to the model.
    }
}
```

Notes:

- The tool **name** defaults to the kebab-cased class basename (`GetWeatherTool` →
  `get-weather-tool`), so set an explicit `#[Name('get-weather')]` for a clean name.
- Inject `ToolContext` for the current user/conversation rather than depending on
  `AiConversation` directly. In the local chat loop it carries the live
  conversation (`$context->conversation`), derived `userId`, `visitorEmail`, and
  `feature`. When the tool is called by an external MCP client it carries the
  authenticated user's id with no conversation, so guard conversation-only logic.

Register the directory containing your tools in `AppServiceProvider`:

```php
use Jvjvjv\CodeTalker\CodeTalkerServiceProvider;

public function register(): void
{
    CodeTalkerServiceProvider::addToolDirectory(
        app_path('Services/Mcp/Tools'),
        'App\\Services\\Mcp\\Tools\\'
    );
}
```

Tools are auto-discovered from registered directories. The `AiSystem::allowed_tools`
array controls which discovered tools are exposed to the model for a given system, by
tool name.

### Signaling a page reload

A tool that changes server state can tell the browser to reload by adding
`_page_reload: true` to its structured result:

```php
return Response::structured([
    'content' => 'Updated your profile.',
    '_page_reload' => true,
]);
```

The turn emits a `page_reload` event for that tool result (see
[Turn events](#turn-events)). The `_page_reload` key itself stays in the
result the model sees — it's a browser-facing side-channel, not something
stripped from the tool's own output.

> **Upgrading from a previous version:** the old `AiToolHandlerContract`
> (`name()`/`description()`/`schema(): array`/`handle(array): array`) is deprecated
> but still discovered and dispatched for backward compatibility. Migrate to
> `Laravel\Mcp\Server\Tool` as shown above. The built-in tools were also renamed
> from snake_case to kebab-case — run the published migration to update any
> persisted `allowed_tools` values.

### Built-in tools

The package includes built-in tools under `src/Services/Mcp/Tools/ChatBot`.

- `fetch-web-page`: Fetches readable text from a URL. `GET` only, HTML and plain text only. Public hosts only unless told otherwise.
- `http-request`: Issues a `GET`/`POST`/`PUT`/`PATCH`/`DELETE` request and returns the decoded response — JSON, XML, plain text, or HTML. See below.
- `get-temporal-information`: Returns the current date and time, optionally in a given IANA timezone or UTC offset.
- `scan-memories`: Searches stored user memories for relevant context.
- `search-web`: Searches Bing, Google, DuckDuckGo, and Brave, then returns structured results plus markdown links/snippets.

To enable the web-search tool for a system, include `search-web` in `AiSystem::allowed_tools`.

`search-web` input schema (high level):

- `query` (required): Search query text.
- `engines` (optional): Any subset of `bing`, `google`, `duckduckgo`, `brave`.
- `page` (optional): Page number for continued searching.
- `per_engine_limit` (optional): Max results per engine (1-10).

`search-web` response includes:

- Per-engine results with `title`, `url`, and `description`.
- `markdown` containing clickable links and snippets.
- `next_page_input` to continue searching on the next page.
- Guidance for asking the model to inspect a specific link in depth.

#### `get-temporal-information`

A model's training data has a cutoff and the system prompt is static, so anything
date-relative is otherwise answered from a guess. This tool returns the wall clock.

Input:

- `timezone` (optional): an IANA identifier (`America/New_York`) or a fixed UTC
  offset (`-05:00`, `+0530`, `+5`). Defaults to `config('app.timezone')`. A value
  that resolves as neither is an error rather than a silent fallback — a
  confidently-wrong time the model then reasons from is worse than a refusal.

The response carries `iso8601`, `utc_iso8601`, `timezone`, `utc_offset`,
`unix_timestamp`, `date`, `time`, `day_of_week`, and `human`, so the model does no
calendar arithmetic on a string.

#### `fetch-web-page`

Inputs: `url` (required), plus optional `keep_html`, `target_selector`,
`truncate_content`, and `request_policy`.

`request_policy` is the same idiom `http-request` uses, minus `allowed_methods` —
this tool is GET-only:

```jsonc
"request_policy": {
  "allow_private_hosts": false,          // default
  "allowed_hosts": ["wiki.internal"]     // optional
}
```

**Omitting it fetches public hosts only.** Reaching a loopback, link-local, or
private-network address requires declaring `allow_private_hosts`. The declaration is
optional; the permission is not.

Redirects are re-validated hop by hop against the same policy, capped at five, and the
response `url` reports the final destination.

Note the difference from `http-request`, which *requires* its policy and refuses
without one. The tools have different surfaces: `http-request` can change server state,
so a missing policy there has no safe interpretation. `fetch-web-page` only reads, and
"public hosts only" is an unambiguous safe default — so it applies that default rather
than spending a round trip asking for a field whose value the caller already wanted.

#### `http-request`

Reach APIs and non-HTML resources. Use `fetch-web-page` for ordinary web pages.

Input:

- `url`, `method` (required): the request. `GET`, `POST`, `PUT`, `PATCH`, `DELETE`.
- `request_policy` (**required**): the model's declared intent — see below.
- `body` (optional): sent as-is; set a `Content-Type` header to describe it.
- `headers` (optional): a **string**, one header per line as `Name: value` — e.g.
  `"Authorization: Bearer abc\nX-Request-Id: 123"` — not a nested object. A flat string
  is far more reliable output for a small/local model's structured tool-calling than a
  nested object is; filtered, see below.
- `keep_html`, `target_selector`, `truncate_content` (optional): as `fetch-web-page`.

Responses are decoded by content type. JSON and XML come back as a structure, not a
string; HTML and other `text/*` types come back as text; anything else (images, PDFs,
`application/octet-stream`) is refused rather than base64-encoded into the transcript.
A response too large to return whole is truncated and flagged, and an oversized
structure is downgraded to truncated text rather than returned as broken JSON.

Both tools share the same two caps, configurable via `.env`:

| Env var | Default | Applies to |
| --- | --- | --- |
| `CODE_TALKER_MAX_BODY_LENGTH` | `150000` bytes | Raw response body, cut immediately after fetch regardless of `truncate_content`. |
| `CODE_TALKER_MAX_CONTENT_LENGTH` | `20000` characters | Decoded/processed content, applied unless a call declines truncation via `truncate_content: false`. |

**The model must declare a request policy, and the tool fails closed without one.**

```jsonc
{
  "url": "https://api.example.com/v1/things",
  "method": "GET",
  "request_policy": {
    "allowed_methods": ["GET"],      // required, non-empty
    "allow_private_hosts": false,    // default false
    "allowed_hosts": ["api.example.com"]  // optional
  }
}
```

A request with no policy is refused before the socket opens, with an error telling the
model what to declare. A request outside the declared policy is refused against the
policy it declared. Non-`http(s)` schemes are refused unconditionally — no policy can
permit `file://`.

**Redirects are not followed blindly.** Both tools disable automatic redirects and
re-run the full policy check against every hop, capped at five, re-deriving credentials
from each hop's own host. Each request also connects to the address that was checked,
rather than resolving the host a second time.

> **A declared policy is a guardrail, not a boundary.** It records intent in the
> `AiLlmMessage` log and keeps requests from reaching internal services by accident, but
> the caller declaring it is the model itself. **Keep these tools out of `allowed_tools`
> for any persona or operator that takes untrusted input**, and restrict outbound network access from
> the PHP process rather than relying on the tool to police itself.

**The model does not supply credentials by default.** `Authorization`, `Cookie`,
`Proxy-Authorization`, `Host`, and the hop-by-hop headers are stripped from
model-supplied headers and reported back in the response, so the model learns why its
auth attempt did nothing. The package attaches credentials from config instead,
matched on exact host:

```php
// config/code-talker.php
'tools' => [
    'http_request' => [
        'credentials' => [
            'api.example.com' => ['Authorization' => 'Bearer '.env('EXAMPLE_API_TOKEN')],
        ],
    ],
],
```

Credentials are applied after filtering, so a configured credential can set a header
the model is forbidden to set. The value never appears in the tool's inputs or its
response.

**Per-`AiSystem` scoping.** The config above is global — every system with these tools
in `allowed_tools` shares it. To restrict a specific system to only its own domain(s),
set `web_tool_policy` on the `AiSystem` record (via `AiSystemManager`, which validates
its shape):

```php
$aiSystemManager->update($system, [
    // ...
    'web_tool_policy' => json_encode([
        'allowed_domains' => ['api.example.com'],
        'credentials' => [
            'api.example.com' => ['Authorization' => 'Bearer '.env('EXAMPLE_API_TOKEN')],
        ],
    ]),
]);
```

`allowed_domains` is enforced server-side by `HostGate` before any DNS resolution or
network call — a request to a host outside the list is refused even if the model's own
`request_policy` would have allowed it, and the check re-runs on every redirect hop.
`credentials` follows the same host-matching and never-echoed rules as the global
config, and takes precedence over it for a matching host. **A system with no
`web_tool_policy` is unrestricted** — this is opt-in scoping, not a default
tightening, so every system created before this feature keeps working unchanged.

**Letting the model supply its own credential (`http-request` only).** Sometimes the
model is handed a credential it must use directly — a token the user pasted into the
conversation, for instance — rather than one an operator can pre-configure. Declaring
`request_policy.allow_credential_headers: true` lets a model-supplied `Authorization`
or `Cookie` header through, but **only when this `AiSystem`'s `web_tool_policy.allowed_domains`
is non-empty.** The declaration alone is never sufficient: it is the model's own
input, and a model acting on injected instructions (from a scraped page, a malicious
chat message) could set it freely. `allowed_domains` is the boundary that actually
matters, because it is set by the operator outside the conversation entirely, and
`HostGate` already refuses any hop — including redirects — that falls outside it. Once
both hold, there is no host this request can reach that the operator did not approve.

A model-supplied credential header:
- wins over a `web_tool_policy`/global-config credential for the same header name;
- is sent only to the exact host the request named — a redirect to a *different* host,
  even one still within `allowed_domains`, does not carry it, the same isolation
  per-host `credentials` already gets;
- is not stripped or reported in `stripped_headers` when it was actually sent.

On an unrestricted system (no `web_tool_policy.allowed_domains`), `allow_credential_headers`
has no effect — `Authorization`/`Cookie` are stripped exactly as before. `fetch-web-page`
has no `headers` input at all, so this only applies to `http-request`.

**The external MCP server has no `AiSystem` at all.** A call from Claude Desktop or any
other MCP client resolves `ToolContext::forUser()` — no conversation, no `AiPersona`, no
`AiSystem`, so no `web_tool_policy` to consult. Without a fallback, `allow_credential_headers`
would be permanently unreachable over that transport regardless of what an operator
configures. `ToolContext::webToolPolicy()` falls back to a global config in exactly
that one case — never when a conversation exists, since that AiSystem is always the
sole authority for its own calls, including its choice to stay unrestricted:

```dotenv
CODE_TALKER_MCP_ALLOWED_DOMAINS=api.example.com,another.example.com
```

Set this to whatever domains your MCP-connected tools are meant to reach with a
caller-supplied credential. Leaving it unset means MCP callers can never satisfy
`allow_credential_headers`, same as before this config existed.

### Injecting extra dependencies into tools

If your tools need objects that aren't in the service container by default (e.g., a service scoped to the current conversation), register a parameter resolver:

```php
CodeTalkerServiceProvider::registerToolParameterResolver(
    fn (AiConversation $conversation): array => [
        'myService' => app(MyService::class)->forConversation($conversation),
    ]
);
```

The resolver is called once per `ChatBotToolRegistry` instantiation, and its return values are passed as `makeWith()` overrides when tools are resolved from the container.

## External MCP Server

Because tools are laravel/mcp `Tool` classes, the same tools can be exposed to
external MCP clients (Claude Desktop, Grok, etc.) through a bundled
`CodeTalkerServer`. This is **disabled by default**. Enable it under the
`code-talker.mcp` config key:

```php
'mcp' => [
    'enabled' => env('CODE_TALKER_MCP_ENABLED', false),

    'web' => [
        'enabled' => true,
        'path' => env('CODE_TALKER_MCP_PATH', 'mcp/code-talker'),
        'middleware' => ['auth:sanctum'],
    ],

    'local' => [
        'enabled' => false,
        'handle' => env('CODE_TALKER_MCP_LOCAL_HANDLE', 'code-talker'),
    ],
],
```

- **web** registers an HTTP MCP endpoint via `Mcp::web()`. Protect it with
  authentication middleware (Sanctum or OAuth per the laravel/mcp docs). The
  authenticated user is mapped to a `ToolContext` so user-scoped tools such as
  `scan-memories` resolve the correct identity. Since there is no conversation in
  this context, `scan-memories` searches across all of the user's memories rather
  than a single feature.
- `scan-memories` implements `shouldRegister()` and is therefore only advertised
  to callers that have a user identity — anonymous callers never see it. If you
  expose the server on a public route, give it _optional_ authentication
  middleware (authenticate when a token is present without rejecting guests) so
  authenticated callers still get the memory tool while anonymous callers get the
  stateless tools.
- **local** registers a stdio server via `Mcp::local()`, runnable through the
  `php artisan mcp:start {handle}` command for local AI assistant integrations.

The server requires laravel/mcp, which is installed as a dependency. See the
[Laravel MCP documentation](https://laravel.com/docs/mcp) for client
configuration, authentication, and the MCP Inspector.

## Memory System

After each completed conversation, `ProcessAiMemoryJob` dispatches and calls `AiMemoryService::processCompletedConversation()`. The service sends the conversation to the same `AiSystem` for analysis and extracts structured memory operations (add / update / remove).

### When memory extraction runs

A conversation is never explicitly "ended" — the browser simply stops sending
messages. Completion is therefore inferred by the
`ai:complete-idle-conversations` command, scheduled every 15 minutes:

```
conversation goes quiet
        │
        ├─ idle_timeout_minutes elapse (default 30)
        │
        ▼
ai:complete-idle-conversations  →  status = Completed
        │
        ▼
AiConversationObserver  →  ProcessAiMemoryJob  (once per conversation)
```

Memories appear up to `idle_timeout_minutes` + 15 after a chat ends. Lower
`conversations.idle_timeout_minutes` for faster extraction at the cost of
splitting conversations where the user pauses mid-chat.

Extraction runs **once per conversation**, not once per message —
`analyzeConversation()` sends the entire transcript on every call, so per-turn
extraction would cost O(N²) tokens in conversation length. If you disable the
package scheduler (`'schedule' => false`), register the command yourself or
memory extraction will never run.

Memories are stored in `AiFeatureMemory` and scoped per user:

- Authenticated users: scoped by `user_id`
- Anonymous visitors: scoped by `visitor_email` (requires `require_visitor_identity = true`)

### Memory categories

| Category           | Description                                       |
| ------------------ | ------------------------------------------------- |
| `preference`       | How the user likes things done                    |
| `domain_knowledge` | Facts about the user not covered by other data    |
| `system_tuning`    | What worked well or poorly in this persona's or operator's approach |

Memories are ranked by `confidence` and `times_reinforced` and injected into the system prompt under `## Learned Insights`. Memories can be reviewed and edited through `AiMemoryManager` (see **Management Services**).

To rebuild all memories for a feature from historical conversations:

```bash
app(\Jvjvjv\CodeTalker\Services\AiMemoryService::class)->rebuildMemories('persona:my-persona');
```

## Management Services

The package registers no admin routes and ships no admin UI. Everything an admin
screen needs is exposed as a service you call from your own controllers, commands,
or tests, under `Jvjvjv\CodeTalker\Services\Management`.

| Service                 | Responsibilities                                                                            |
| ----------------------- | ------------------------------------------------------------------------------------------- |
| `AiSystemManager`       | Create/update/delete/duplicate systems, sync feature defaults, list provider models          |
| `AiSystemPromptManager` | Reusable system prompt CRUD, clearing references on delete                                   |
| `AiPersonaManager`      | Persona CRUD, per-persona usage rollups, available systems, available tools                  |
| `AiOperatorManager`     | Operator CRUD, per-operator run/usage rollups, available tools                               |
| `AiConversationManager` | Filter and search conversations, inspect one, queue usage backfill                           |
| `AiMemoryManager`       | Memory CRUD, triage-ordered listing, per-feature rebuild                                     |

Each manager validates its own input and throws `ValidationException` on bad data,
so a controller can let Laravel render the errors as usual:

```php
use Jvjvjv\CodeTalker\Services\Management\AiSystemManager;

public function store(Request $request, AiSystemManager $systems)
{
    $system = $systems->create($request->all());

    return redirect()->route('your.systems.index');
}
```

If you would rather validate in a form request, take the rules from the manager
so the domain constraints stay in one place:

```php
public function rules(): array
{
    return AiSystemManager::createRules($this->all());
}
```

Operations that have a side effect beyond the record report it, so you can build
an accurate confirmation message:

```php
$deactivatedBots = $systems->delete($system);       // bots are deactivated, not deleted
$orphanedSystems = $prompts->delete($prompt);       // systems have their prompt cleared
```

### What to be aware of

- **`provider` and `model` are immutable** once a system exists. Changing either
  would invalidate the stored capability flags with no way to detect it, so
  `update()` ignores both. Create a new system instead.
- **A feature has exactly one default system.** Assigning a feature default that
  another system holds takes it from that system. `claimedFeatures()` tells you
  which are already spoken for, optionally ignoring the system being edited.
- **`config`, `credentials`, and `pricing_profile`** may be passed as JSON strings
  or arrays; the manager decodes strings before persisting.
- **`custom_system_prompt`** is not a column. Supplying it without a
  `system_prompt_id` creates an `AiSystemPrompt` and links it.

### Authorization

Authorization is entirely yours — the services do not check it. `admin_middleware`
remains in the config for host apps that kept a published copy of the old admin
route file, and defaults to `['web', 'auth', 'can:manage-ai-tools']`.

## Scheduled Jobs

The package registers five jobs automatically (requires Laravel's scheduler to be running):

| Job                              | Schedule                   | Description                                             |
| -------------------------------- | -------------------------- | ------------------------------------------------------- |
| `ai:sync-conversation-usage`     | Twice daily (00:00, 12:00) | Syncs token counts and cost to `AiConversation`         |
| `BackfillConversationUsageJob`   | Daily at 02:30             | Backfills usage for conversations missing cost data     |
| `ai:prune-provider-exchanges`    | Daily at 03:00             | Removes `ai_provider_exchanges` rows past retention     |
| `ai:prune-turn-events`           | Daily at 03:15             | Removes finished turn runs past `turns.retention_days`  |
| `ai:complete-idle-conversations` | Every 15 minutes           | Completes idle conversations, triggering memory extract |

Disable automatic scheduling in config and register manually if needed:

```php
// config/code-talker.php
'schedule' => false,

// Your app's Console\Kernel or routes/console.php
Schedule::command('ai:sync-conversation-usage')->twiceDaily();
```

## Artisan Commands

```bash
# Detect and store capability flags for all AiSystem records
php artisan ai:backfill-system-capabilities

# Backfill usage/cost data for conversations missing it
php artisan ai:backfill-conversation-usage

# Sync current token/cost totals to ai_conversations
php artisan ai:sync-conversation-usage

# Delete ai_provider_exchanges rows older than raw_exchanges.retention_days
php artisan ai:prune-provider-exchanges

# Delete finished turn runs (and their events) older than turns.retention_days
php artisan ai:prune-turn-events

# Mark idle conversations Completed, triggering memory extraction
php artisan ai:complete-idle-conversations
php artisan ai:complete-idle-conversations --minutes=60 --dry-run
```

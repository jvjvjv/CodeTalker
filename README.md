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

## Configuration

`config/code-talker.php` controls package-wide behavior:

| Key                                  | Default                                  | Description                                                      |
| ------------------------------------ | ---------------------------------------- | ---------------------------------------------------------------- |
| `user_model`                         | `App\Models\User::class`                 | Eloquent model used for authenticated users                      |
| `reserved_slugs`                     | `[]`                                     | Additional slugs that cannot be used for root-path chatbots      |
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
| `temperature`      | Sampling temperature (overrides bot-level default)                                |
| `context_length`   | Context window for local models (LM Studio)                                       |
| `enable_thinking`  | Enable extended thinking / reasoning output (Anthropic)                           |
| `allowed_tools`    | Array of tool names the model may invoke                                          |
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
supply — open one with `AiChatBotConversationService::startConversation()` first.

### Two writers

`continue()` attaches a conversation participant, which arms laravel/ai's
remembering middleware. That middleware persists both messages of a turn. If you
*also* drive `AiChatBotConversationService`, every turn is written twice.

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

## Chat Bots

An `AiChatBot` defines a user-facing persona. Create one with `AiChatBotManager`. Key fields:

| Field                      | Description                                          |
| -------------------------- | ---------------------------------------------------- |
| `ai_system_id`             | The backing `AiSystem`                               |
| `name`                     | Display name                                         |
| `slug`                     | URL-safe identifier, must be unique                  |
| `access_path`              | `chat` → `/chat/{slug}`, `root` → `/{slug}`          |
| `prompt_template`          | System prompt with optional placeholders (see below) |
| `require_visitor_identity` | Prompt anonymous visitors for name and email         |
| `tools_enabled`            | Whether the bot may invoke registered tools          |
| `temperature`              | Overrides `AiSystem` temperature for this bot        |

Chatbot authentication and authorization are not managed by this package. The
consuming application must decide which users or guests can reach chatbot
routes by applying its own middleware, gates, or policies around the package
routes.

### Prompt template placeholders

These tokens are replaced when a conversation starts:

| Placeholder           | Value                                           |
| --------------------- | ----------------------------------------------- |
| `{{bot_name}}`        | Bot's display name                              |
| `{{bot_slug}}`        | Bot's slug                                      |
| `{{bot_description}}` | Bot's description field                         |
| `{{visitor_name}}`    | Name collected from anonymous visitor (if any)  |
| `{{visitor_email}}`   | Email collected from anonymous visitor (if any) |

The final system prompt is assembled as: `AiSystemPrompt.content` + prompt template + `## Learned Insights` (injected memories).

### Driving a turn

The package registers no routes and renders no pages. You write the endpoint;
the package supplies the turn.

```php
use Jvjvjv\CodeTalker\Services\AiChatBotConversationService;
use Jvjvjv\CodeTalker\Services\ChatBot\SseFrameEncoder;

public function message(Request $request, AiChatBot $bot,
    AiChatBotConversationService $chat, SseFrameEncoder $encoder)
{
    $validated = $request->validate(['message' => ['required', 'string']]);

    $conversation = $this->resolveConversation($request, $bot)
        ?? $chat->startConversation($bot, $request->user());

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
| `message_delta`         | `delta.stop_reason`, `usage`                                 |
| `message_stop`          | —                                                            |
| `error`                 | `message`, `reason` (`max_stream_duration`/`provider_error`) |

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

### Resolving conversations across requests

The package used to keep this in the session and a cookie. It no longer does —
your endpoint decides. `AiConversation::findByChatHashOrUuid()` is the lookup,
and `$conversation->chat_hash` is a stable shareable handle that
`continueConversation()` keeps current.

### Presentation queries

`ChatBotPresenter` keeps the queries a chat UI needs:

```php
$presenter->transcript($conversation);            // visible messages, system prompt excluded
$presenter->totalCostUsd($bot);                   // lifetime cost for a bot
$presenter->conversationsFor($user, $bots);       // an authenticated user's conversations
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
array controls which discovered tools are exposed to the model for a given bot, by
tool name.

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
- `headers` (optional): filtered, see below.
- `keep_html`, `target_selector`, `truncate_content` (optional): as `fetch-web-page`.

Responses are decoded by content type. JSON and XML come back as a structure, not a
string; HTML and other `text/*` types come back as text; anything else (images, PDFs,
`application/octet-stream`) is refused rather than base64-encoded into the transcript.
A response too large to return whole is truncated and flagged, and an oversized
structure is downgraded to truncated text rather than returned as broken JSON.

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
> for any bot that takes untrusted input**, and restrict outbound network access from
> the PHP process rather than relying on the tool to police itself.

**The model never supplies credentials.** `Authorization`, `Proxy-Authorization`,
`Cookie`, `Host`, and the hop-by-hop headers are stripped from model-supplied headers
and reported back in the response, so the model learns why its auth attempt did
nothing. The package attaches credentials from config instead, matched on exact host:

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
| `system_tuning`    | What worked well or poorly in this bot's approach |

Memories are ranked by `confidence` and `times_reinforced` and injected into the system prompt under `## Learned Insights`. Memories can be reviewed and edited through `AiMemoryManager` (see **Management Services**).

To rebuild all memories for a feature from historical conversations:

```bash
app(\Jvjvjv\CodeTalker\Services\AiMemoryService::class)->rebuildMemories('chat-bot:my-bot');
```

## Management Services

The package registers no admin routes and ships no admin UI. Everything an admin
screen needs is exposed as a service you call from your own controllers, commands,
or tests, under `Jvjvjv\CodeTalker\Services\Management`.

| Service                 | Responsibilities                                                                            |
| ----------------------- | ------------------------------------------------------------------------------------------- |
| `AiSystemManager`       | Create/update/delete/duplicate systems, sync feature defaults, list provider models          |
| `AiSystemPromptManager` | Reusable system prompt CRUD, clearing references on delete                                   |
| `AiChatBotManager`      | Chat bot CRUD, per-bot usage rollups, available systems, available tools                     |
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

The package registers four jobs automatically (requires Laravel's scheduler to be running):

| Job                              | Schedule                   | Description                                             |
| -------------------------------- | -------------------------- | ------------------------------------------------------- |
| `ai:sync-conversation-usage`     | Twice daily (00:00, 12:00) | Syncs token counts and cost to `AiConversation`         |
| `BackfillConversationUsageJob`   | Daily at 02:30             | Backfills usage for conversations missing cost data     |
| `ai:prune-provider-exchanges`    | Daily at 03:00             | Removes `ai_provider_exchanges` rows past retention     |
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

# Mark idle conversations Completed, triggering memory extraction
php artisan ai:complete-idle-conversations
php artisan ai:complete-idle-conversations --minutes=60 --dry-run
```

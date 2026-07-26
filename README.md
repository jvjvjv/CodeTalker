# code-talker

Multi-provider AI communications package for Laravel — chatbots, streaming, tool-use, memory, and admin management.

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

The package installs the Laravel Inertia adapter as a runtime dependency because
its public and admin controllers render Inertia responses.

Publish the config and migrations, then run them:

```bash
php artisan vendor:publish --tag=code-talker-config
php artisan vendor:publish --tag=code-talker-migrations
php artisan migrate
```

If you want to customize the package route files directly in the host app,
publish them too:

```bash
php artisan vendor:publish --tag=code-talker-routes
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
| `middleware`                         | `['web']`                                | Middleware applied to public chat routes                         |
| `admin_middleware`                   | `['web', 'auth', 'can:manage-ai-tools']` | Middleware applied to admin routes                               |
| `reserved_slugs`                     | `[]`                                     | Additional slugs that cannot be used for root-path chatbots      |
| `schedule`                           | `true`                                   | Set to `false` to disable the package's automatic scheduled jobs |
| `conversations.idle_timeout_minutes` | `30`                                     | Inactivity before a conversation is marked `Completed`           |
| `inertia.components.chat_bot`        | `ai/ChatBot`                             | Inertia component rendered for the chat page                     |
| `inertia.components.chat_bots_index` | `ai/ChatBotsIndex`                       | Inertia component rendered for the bot list                      |

### Suggested host-app packages

- `bspdx/keystone` is suggested if you want a ready-made host-app authorization layer for the package's admin AI routes.

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

An `AiSystem` record represents a fully configured provider endpoint. Create one through the admin UI at `/admin/ai/systems` or via a seeder. Key fields:

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

Map a feature key to a default `AiSystem` via the `ai_system_feature_defaults` table (managed through `/admin/ai/systems`). This decouples application code from specific system IDs.

## Chat Bots

An `AiChatBot` defines a user-facing persona. Create one at `/admin/ai/chat-bots`. Key fields:

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

### Auto-registered routes

All routes use the middleware from `code-talker.middleware`.

If `routes/codetalker-chatbots.php` or `routes/codetalker-admin.php` exists in
the host app, the package will load those published copies instead of its
internal defaults.

The package does not treat any bot as inherently public or private. If some
chatbot routes should require authentication or further authorization, enforce
that entirely in the consuming application by changing `code-talker.middleware`
or wrapping the package routes in your own authorization layer.

| Route                        | Description                                          |
| ---------------------------- | ---------------------------------------------------- |
| `GET /chats`                 | List of available bots (Inertia: `ai/ChatBotsIndex`) |
| `GET /chats/statuses`        | JSON model-readiness status for all bots             |
| `GET /chat/{slug}`           | Chat UI for a bot (Inertia: `ai/ChatBot`)            |
| `GET /chat/{slug}/new`       | Start a new conversation                             |
| `GET /chat/{slug}/status`    | JSON readiness status for one bot                    |
| `POST /chat/{slug}/warmup`   | Warm up the model                                    |
| `POST /chat/{slug}/messages` | Send a message (SSE stream)                          |
| `POST /chat/{slug}/reset`    | Clear current conversation                           |
| `POST /chat/{slug}/switch`   | Switch to a different conversation from history      |
| `GET /chat/{slug}/{hash}`    | Load a conversation by its shareable hash            |

Root-access-path bots duplicate the above at `/{slug}` instead of `/chat/{slug}`.

### Conversation state

Per-bot conversation state — the current conversation and the switcher history — lives in the server-side session. Only the current conversation's id is mirrored into a single 180-day `ai_chat_bot_current` cookie, so the request header stays small no matter how many bots a visitor has used. History is capped at 25 entries and is never written to a cookie. Legacy per-bot `ai_chat_bot_conversations_{id}` cookies from before 0.7.1 are forgotten on sight. Conversations are also shareable via `/chat/{slug}/{hash}`.

## Frontend Integration

This package ships the chat **backend** — routes, streaming, persistence — and no UI
components. You build the chat interface in your own framework and design system.
What follows is the complete contract between the two, so you never need to read
package source to do it.

> **These contracts are public API.** The Inertia props and the SSE event shapes
> below follow this package's semantic version: renaming a prop, changing an event
> payload, or removing an event is a breaking change and is released as one.

### Publishing types and a stream client

```bash
# TypeScript declarations for the props and stream events.
# Safe to re-publish on upgrade — these track the package.
php artisan vendor:publish --tag=code-talker-types

# A dependency-free stream client. Copied into your app and yours to edit
# from that point on; upgrades will not re-publish over your changes.
php artisan vendor:publish --tag=code-talker-client
```

Both are optional. The client is a starting point, not a dependency — it imports
nothing but standard browser APIs and works with React, Vue, Svelte, or none.

### Inertia component names

The two pages render `ai/ChatBot` and `ai/ChatBotsIndex` by default. Point them at
your own component paths in `config/code-talker.php`:

```php
'inertia' => [
    'components' => [
        'chat_bot' => 'ai/ChatBot',
        'chat_bots_index' => 'ai/ChatBotsIndex',
    ],
],
```

### Props: the chat page

Rendered by `GET /chat/{slug}` and `GET /chat/{slug}/{hash}`.

| Prop | Type | Notes |
| --- | --- | --- |
| `bot.name` | `string` | |
| `bot.description` | `string \| null` | |
| `bot.require_visitor_identity` | `boolean` | Whether the bot asks anonymous visitors for a name and email |
| `bot.total_cost_usd` | `number` | Lifetime spend across every conversation with this bot |
| `messages` | `ChatMessage[]` | The visible transcript; the system prompt is never included |
| `messages[].role` | `'user' \| 'assistant'` | |
| `messages[].content` | `string` | |
| `messages[].reasoning_content` | `string \| null` | Populated for reasoning models |
| `messages[].blocks` | `MessageBlock[] \| null` | Ordered `{type: 'text' \| 'reasoning', content}` runs; `null` on older records |
| `history` | `ChatHistoryEntry[]` | The conversation switcher |
| `history[].handle` | `string` | Opaque id to POST to `switchUrl` |
| `history[].label` | `string` | Conversation title, or `'New chat'` |
| `history[].is_current` | `boolean` | |
| `history[].is_stale` | `boolean` | No activity for 7 days |
| `history[].updated_at` | `string` | Human-readable, e.g. `'3 hours ago'` |
| `history[].cost_usd` | `number \| null` | |
| `messageUrl` | `string` | POST here to send a message (SSE response) |
| `resetUrl` | `string` | POST to start a new conversation |
| `switchUrl` | `string` | POST a `conversation` handle to switch |
| `statusUrl` | `string` | GET model readiness |
| `warmupUrl` | `string` | POST to warm the model |
| `chatUrl` | `string \| null` | Shareable link, `null` until the conversation has a hash |
| `chatUrlBase` | `string` | e.g. `/chat/my-bot/`, for building links client-side |
| `showIdentityForm` | `boolean` | Whether to collect a visitor name and email before the first message |
| `chatHash` | `string \| null` | **Only present on `/chat/{slug}/{hash}`.** Absent from `/chat/{slug}` |

`showIdentityForm` is derived differently on each route, deliberately. On
`/chat/{slug}` it is true when there is no stored conversation at all; on
`/chat/{slug}/{hash}` the conversation exists by definition, so it is true when
that conversation has no non-system messages yet.

### Props: the index page

Rendered by `GET /chats`.

| Prop | Type | Notes |
| --- | --- | --- |
| `bots[].slug` | `string` | |
| `bots[].name` | `string` | |
| `bots[].description` | `string \| null` | |
| `bots[].new_chat_url` | `string` | |
| `bots[].status_url` | `string` | |
| `bots[].conversations` | `array` | **Empty for guests** — only populated for an authenticated user |
| `bots[].conversations[].title` | `string` | |
| `bots[].conversations[].updated_at` | `string \| null` | ISO 8601 |
| `bots[].conversations[].updated_at_human` | `string` | e.g. `'3 hours ago'` |
| `bots[].conversations[].is_stale` | `boolean` | |

### The message stream

`POST {messageUrl}` responds with `text/event-stream`. Every frame is
`data: <json>\n\n`. The response also carries an **`X-Chat-Hash`** header with the
conversation's shareable hash — read it from the response headers to update the
URL after the first message.

| Event | Payload | Meaning |
| --- | --- | --- |
| `status` | `{phase, message}` | Progress before tokens arrive. `phase` is `request_received` then `model_loading` |
| `message_start` | `{message: {usage: {input_tokens: null}}}` | The turn has begun. Sent exactly once |
| `content_block_delta` | `{delta: {text}}` | Append `text` to the answer |
| `reasoning_block_delta` | `{delta: {reasoning}}` | Append `reasoning` to the thinking trace |
| `message_delta` | `{delta: {stop_reason}, usage: {input_tokens, output_tokens}}` | Terminal summary. `stop_reason` is `end_turn`, `max_tokens`, or `tool_use` |
| `message_stop` | `{}` | The turn's content is complete |
| `error` | `{message, reason?}` | The turn failed. See below |

A turn that finishes normally ends with the literal sentinel:

```
data: [DONE]

```

**An error frame is terminal on its own — `[DONE]` does not follow it.** Treat any
`error` as the end of the turn rather than waiting for a sentinel that never
arrives. `reason` is `max_stream_duration` when the wall-clock guard cut the turn
off, `provider_error` for a provider failure, and absent for a recoverable
in-stream error or a transport-level failure. Content already delivered via
`content_block_delta` before the error is still valid and is persisted server-side
— do not discard it.

Tool calls are never forwarded to the browser. A turn that uses tools looks
exactly like one that does not, apart from taking longer.

> **Known wart:** the `status` frames predate `message_start` and overlap with it.
> Both are emitted today and both are part of the current contract. A future major
> version may drop `status` in favour of `message_start` alone.

### Consuming the stream

The published client wraps all of the above:

```ts
import { streamChatTurn } from './code-talker-stream';

const turn = streamChatTurn(props.messageUrl, { message: 'Hello' }, {
    onChatHash: (hash) => history.replaceState(null, '', props.chatUrlBase + hash),
    onStatus: (phase) => setPhase(phase),
    onText: (delta) => setAnswer((prev) => prev + delta),
    onReasoning: (delta) => setThinking((prev) => prev + delta),
    onDone: ({ stopReason, usage }) => setDone(stopReason, usage),
    onError: ({ message, reason }) => setError(message, reason),
});

// Cancel mid-turn. The server still persists whatever was generated.
turn.abort();

await turn.done;
```

If the bot has `require_visitor_identity` and the visitor is not authenticated,
send `name` and `email` alongside `message` on the first request of a
conversation.

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

- `fetch-web-page`: Fetches readable text from a URL.
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

Memories are ranked by `confidence` and `times_reinforced` and injected into the system prompt under `## Learned Insights`. Memories can be reviewed and edited at `/admin/ai/memories`.

To rebuild all memories for a feature from historical conversations:

```bash
# Via admin UI at /admin/ai/memories — use the "Rebuild" action
# Or via code:
app(\Jvjvjv\CodeTalker\Services\AiMemoryService::class)->rebuildMemories('chat-bot:my-bot');
```

## Admin Routes

The admin route group is registered under `/admin/ai/*` and uses the middleware
defined in `code-talker.admin_middleware`, which defaults to `['web', 'auth', 'can:manage-ai-tools']`.

If your host app does not already provide that gate, wire it yourself or change
`code-talker.admin_middleware` to the authorization middleware your application
already uses.

All admin routes are under `/admin/ai` and require the `can:manage-ai-tools` gate (configurable via `admin_middleware`).

| Prefix                     | Resource                                     |
| -------------------------- | -------------------------------------------- |
| `/admin/ai/systems`        | AI Systems CRUD + interaction log viewer     |
| `/admin/ai/system-prompts` | Reusable system prompt CRUD                  |
| `/admin/ai/chat-bots`      | Chat bot CRUD                                |
| `/admin/ai/conversations`  | Conversation viewer + usage backfill trigger |
| `/admin/ai/memories`       | Feature memory CRUD + per-feature rebuild    |

Define the `manage-ai-tools` gate in your `AppServiceProvider` or `AuthServiceProvider`:

```php
Gate::define('manage-ai-tools', fn ($user) => (bool) $user->is_admin);
```

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

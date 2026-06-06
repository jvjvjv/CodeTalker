# code-talker

Multi-provider AI communications package for Laravel — chatbots, streaming, tool-use, memory, and admin management.

## Requirements

- PHP ^8.2
- Laravel ^12.0 || ^13.0

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

## Configuration

`config/code-talker.php` controls package-wide behavior:

| Key | Default | Description |
|-----|---------|-------------|
| `user_model` | `App\Models\User::class` | Eloquent model used for authenticated users |
| `middleware` | `['web']` | Middleware applied to public chat routes |
| `admin_middleware` | `['web', 'auth', 'can:manage-ai-tools']` | Middleware applied to admin routes |
| `reserved_slugs` | `[]` | Additional slugs that cannot be used for root-path chatbots |
| `schedule` | `true` | Set to `false` to disable the package's automatic scheduled jobs |

### Suggested host-app packages

- `bspdx/keystone` is suggested if you want a ready-made host-app authorization layer for the package's admin AI routes.

### Provider environment variables

**Anthropic**
```
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-sonnet-4-6
ANTHROPIC_MAX_TOKENS=1024
ANTHROPIC_API_VERSION=2023-06-01
ANTHROPIC_BASE_URL=https://api.anthropic.com/v1
```

**OpenAI**
```
OPENAI_API_KEY=
OPENAI_MODEL=gpt-4o-mini
OPENAI_MAX_TOKENS=1024
OPENAI_BASE_URL=https://api.openai.com/v1
```

**Google Gemini**
```
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash
GEMINI_MAX_TOKENS=1024
```

**xAI Grok**
```
GROK_MODEL=grok-3-mini
GROK_MAX_TOKENS=1024
GROK_BASE_URL=https://api.x.ai/v1
```

**LM Studio**
```
LMSTUDIO_SERVER_URL=http://localhost:1234
LMSTUDIO_MODEL=
LMSTUDIO_MAX_TOKENS=1024
```

> Config values are fallback defaults. `AiSystem` database records override them at runtime.

## AI Systems

An `AiSystem` record represents a fully configured provider endpoint. Create one through the admin UI at `/admin/ai/systems` or via a seeder. Key fields:

| Field | Description |
|-------|-------------|
| `provider` | One of: `anthropic`, `openai`, `openai_compatible`, `gemini`, `grok`, `lm-studio` |
| `model` | Provider-specific model name |
| `api_key` | Stored encrypted |
| `max_tokens` | Maximum output tokens per request |
| `temperature` | Sampling temperature (overrides bot-level default) |
| `context_length` | Context window for local models (LM Studio) |
| `enable_thinking` | Enable extended thinking / reasoning output (Anthropic) |
| `allowed_tools` | Array of tool names the model may invoke |
| `system_prompt_id` | Optional FK to an `AiSystemPrompt` record |
| `is_active` | Inactive systems are rejected by the factory |

### Getting a client in code

```php
use Jvjvjv\CodeTalker\Services\AiClientFactory;
use Jvjvjv\CodeTalker\Models\AiSystem;

// From a specific system record
$client = app(AiClientFactory::class)->forSystem(AiSystem::find($id));

// From a feature key (resolves the default system for that feature)
$client = app(AiClientFactory::class)->forFeature('my-feature');
```

Both return an `AiClientContract` instance with a fluent builder:

```php
$response = $client
    ->withSystem('You are a helpful assistant.')
    ->withMaxTokens(2048)
    ->withTemperature(0.7)
    ->message([
        ['role' => 'user', 'content' => 'Hello!'],
    ]);
```

### Feature defaults

Map a feature key to a default `AiSystem` via the `ai_system_feature_defaults` table (managed through `/admin/ai/systems`). This decouples application code from specific system IDs.

## Chat Bots

An `AiChatBot` defines a user-facing persona. Create one at `/admin/ai/chat-bots`. Key fields:

| Field | Description |
|-------|-------------|
| `ai_system_id` | The backing `AiSystem` |
| `name` | Display name |
| `slug` | URL-safe identifier, must be unique |
| `access_path` | `chat` → `/chat/{slug}`, `root` → `/{slug}` |
| `prompt_template` | System prompt with optional placeholders (see below) |
| `require_visitor_identity` | Prompt anonymous visitors for name and email |
| `tools_enabled` | Whether the bot may invoke registered tools |
| `temperature` | Overrides `AiSystem` temperature for this bot |

Chatbot authentication and authorization are not managed by this package. The
consuming application must decide which users or guests can reach chatbot
routes by applying its own middleware, gates, or policies around the package
routes.

### Prompt template placeholders

These tokens are replaced when a conversation starts:

| Placeholder | Value |
|-------------|-------|
| `{{bot_name}}` | Bot's display name |
| `{{bot_slug}}` | Bot's slug |
| `{{bot_description}}` | Bot's description field |
| `{{visitor_name}}` | Name collected from anonymous visitor (if any) |
| `{{visitor_email}}` | Email collected from anonymous visitor (if any) |

The final system prompt is assembled as: `AiSystemPrompt.content` + prompt template + `## Learned Insights` (injected memories).

### Auto-registered routes

All routes use the middleware from `code-talker.middleware`.

The package does not treat any bot as inherently public or private. If some
chatbot routes should require authentication or further authorization, enforce
that entirely in the consuming application by changing `code-talker.middleware`
or wrapping the package routes in your own authorization layer.

| Route | Description |
|-------|-------------|
| `GET /chats` | List of available bots (Inertia: `ai/ChatBotsIndex`) |
| `GET /chats/statuses` | JSON model-readiness status for all bots |
| `GET /chat/{slug}` | Chat UI for a bot (Inertia: `ai/ChatBot`) |
| `GET /chat/{slug}/new` | Start a new conversation |
| `GET /chat/{slug}/status` | JSON readiness status for one bot |
| `POST /chat/{slug}/warmup` | Warm up the model |
| `POST /chat/{slug}/messages` | Send a message (SSE stream) |
| `POST /chat/{slug}/reset` | Clear current conversation |
| `POST /chat/{slug}/switch` | Switch to a different conversation from history |
| `GET /chat/{slug}/{hash}` | Load a conversation by its shareable hash |

Root-access-path bots duplicate the above at `/{slug}` instead of `/chat/{slug}`.

### Conversation state

The browser's active and historical conversations are stored in a session key and a 180-day encrypted cookie (`ai_chat_bot_conversations_{id}`). Conversations are also shareable via `/chat/{slug}/{hash}`.

## Tool Registration

Tools follow an MCP-style contract. Implement `AiToolHandlerContract`:

```php
use Jvjvjv\CodeTalker\Contracts\Mcp\AiToolHandlerContract;

class GetWeatherTool implements AiToolHandlerContract
{
    public function name(): string { return 'get_weather'; }

    public function description(): string
    {
        return 'Returns current weather for a given city.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'city' => ['type' => 'string', 'description' => 'City name'],
            ],
            'required' => ['city'],
        ];
    }

    public function handle(array $input): array
    {
        // ... fetch weather ...
        return ['temperature' => '72°F', 'condition' => 'Sunny'];
    }
}
```

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

Tools are auto-discovered from registered directories. The `AiSystem::allowed_tools` array controls which discovered tools are actually exposed to the model for a given bot.

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

## Memory System

After each completed conversation, `ProcessAiMemoryJob` dispatches and calls `AiMemoryService::processCompletedConversation()`. The service sends the conversation to the same `AiSystem` for analysis and extracts structured memory operations (add / update / remove).

Memories are stored in `AiFeatureMemory` and scoped per user:
- Authenticated users: scoped by `user_id`
- Anonymous visitors: scoped by `visitor_email` (requires `require_visitor_identity = true`)

### Memory categories

| Category | Description |
|----------|-------------|
| `preference` | How the user likes things done |
| `domain_knowledge` | Facts about the user not covered by other data |
| `system_tuning` | What worked well or poorly in this bot's approach |

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

| Prefix | Resource |
|--------|----------|
| `/admin/ai/systems` | AI Systems CRUD + interaction log viewer |
| `/admin/ai/system-prompts` | Reusable system prompt CRUD |
| `/admin/ai/chat-bots` | Chat bot CRUD |
| `/admin/ai/conversations` | Conversation viewer + usage backfill trigger |
| `/admin/ai/memories` | Feature memory CRUD + per-feature rebuild |

Define the `manage-ai-tools` gate in your `AppServiceProvider` or `AuthServiceProvider`:

```php
Gate::define('manage-ai-tools', fn ($user) => (bool) $user->is_admin);
```

## Scheduled Jobs

The package registers two jobs automatically (requires Laravel's scheduler to be running):

| Job | Schedule | Description |
|-----|----------|-------------|
| `ai:sync-conversation-usage` | Twice daily (00:00, 12:00) | Syncs token counts and cost to `AiConversation` |
| `BackfillConversationUsageJob` | Daily at 02:30 | Backfills usage for conversations missing cost data |

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
```

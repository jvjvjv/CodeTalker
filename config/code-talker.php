<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model class used for authenticated users. AI models that
    | hold user relationships (AiConversation, AiFeatureMemory, etc.) resolve
    | the user via this class.
    |
    */

    'user_model' => \App\Models\User::class,

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to the public chat routes and admin AI routes.
    |
    */

    'middleware' => ['web'],

    'admin_middleware' => ['web', 'auth', 'can:manage-ai-tools'],

    /*
    |--------------------------------------------------------------------------
    | Reserved Root Slugs
    |--------------------------------------------------------------------------
    |
    | Slugs that cannot be used for root-access-path chatbots. Add any
    | application-specific paths that should be reserved alongside the
    | package defaults (login, logout, admin, api, etc.).
    |
    */

    'reserved_slugs' => [],

    /*
    |--------------------------------------------------------------------------
    | Scheduled Jobs
    |--------------------------------------------------------------------------
    |
    | Set to false to disable the package's scheduled jobs (conversation usage
    | sync and backfill). Useful if you register them manually.
    |
    */

    'schedule' => true,

    /*
    |--------------------------------------------------------------------------
    | Inertia Components
    |--------------------------------------------------------------------------
    |
    | The Inertia components the public chat pages render. Point these at your
    | own component paths if your app does not use the defaults; the props
    | passed to them are unchanged either way, and are documented under
    | "Frontend Integration" in the README.
    |
    */

    'inertia' => [
        'components' => [
            'chat_bot' => 'ai/ChatBot',
            'chat_bots_index' => 'ai/ChatBotsIndex',
        ],
    ],

    'services' => [
        'brave' => [
            'search_api_key' => env('BRAVE_SEARCH_API_KEY', ''),
        ],
        'bing' => [
            'search_api_key' => env('BING_SEARCH_API_KEY', ''),
            'endpoint' => env('BING_SEARCH_ENDPOINT', 'https://api.bing.microsoft.com/v7.0/search'),
        ],
        'google' => [
            'search_api_key' => env('GOOGLE_SEARCH_API_KEY', ''),
            'search_engine_id' => env('GOOGLE_SEARCH_ENGINE_ID', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversations
    |--------------------------------------------------------------------------
    |
    | `idle_timeout_minutes` is how long an Active conversation may go without
    | a new user or assistant message before `ai:complete-idle-conversations`
    | marks it Completed. Completion is what triggers memory extraction (once
    | per conversation, via AiConversationObserver), so this also controls how
    | long after a chat ends its memories appear.
    |
    */

    'conversations' => [
        'idle_timeout_minutes' => (int) env('CODE_TALKER_CONVERSATION_IDLE_MINUTES', 30),

        // Wall-clock ceiling (seconds) for a single streamed chat turn, across
        // all tool steps and continuation attempts. Guards against a runaway
        // generation — e.g. a reasoning model that loops until it overflows the
        // provider's context window — hanging the turn indefinitely. When the
        // ceiling is passed the turn is aborted and logged as an error. Set to
        // 0 (or a negative value) to disable the guard.
        'max_stream_seconds' => (int) env('CODE_TALKER_MAX_STREAM_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | External MCP Server
    |--------------------------------------------------------------------------
    |
    | The same chat-bot tools can be exposed to external MCP clients (Claude
    | Desktop, Grok, etc.) through a laravel/mcp server. This is disabled by
    | default. When enabled, the package registers the server using the Mcp
    | facade during boot.
    |
    | The web transport is HTTP and should be protected with authentication
    | middleware — the authenticated user is mapped to the tool ToolContext so
    | user-scoped tools (e.g. scan-memories) resolve the correct identity. The
    | local (stdio) transport runs as the `mcp:start {handle}` Artisan command.
    |
    */

    'mcp' => [
        'enabled' => env('CODE_TALKER_MCP_ENABLED', false),

        'web' => [
            'enabled' => env('CODE_TALKER_MCP_WEB_ENABLED', true),
            'path' => env('CODE_TALKER_MCP_PATH', 'mcp/code-talker'),
            'middleware' => ['auth:sanctum'],
        ],

        'local' => [
            'enabled' => env('CODE_TALKER_MCP_LOCAL_ENABLED', false),
            'handle' => env('CODE_TALKER_MCP_LOCAL_HANDLE', 'code-talker'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Default configuration for each AI provider. AiSystem database records
    | hold the credentials and model settings used at runtime (bridged into
    | laravel/ai providers); these entries supply fallback base URLs, the
    | Anthropic API version, the LM Studio server URL, and token pricing used
    | by conversation usage tracking.
    |
    */

    'providers' => [

        'anthropic' => [
            'api_version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'pricing' => [
                'default' => [
                    'input_per_million' => (float) env('ANTHROPIC_DEFAULT_INPUT_PER_MILLION', 3.00),
                    'output_per_million' => (float) env('ANTHROPIC_DEFAULT_OUTPUT_PER_MILLION', 15.00),
                ],
                'models' => [
                    'claude-haiku-3-5' => [
                        'input_per_million' => (float) env('ANTHROPIC_HAIKU_35_INPUT_PER_MILLION', 0.80),
                        'output_per_million' => (float) env('ANTHROPIC_HAIKU_35_OUTPUT_PER_MILLION', 4.00),
                    ],
                    'claude-sonnet-3-7' => [
                        'input_per_million' => (float) env('ANTHROPIC_SONNET_37_INPUT_PER_MILLION', 3.00),
                        'output_per_million' => (float) env('ANTHROPIC_SONNET_37_OUTPUT_PER_MILLION', 15.00),
                    ],
                    'claude-sonnet-4' => [
                        'input_per_million' => (float) env('ANTHROPIC_SONNET_4_INPUT_PER_MILLION', 3.00),
                        'output_per_million' => (float) env('ANTHROPIC_SONNET_4_OUTPUT_PER_MILLION', 15.00),
                    ],
                    'claude-sonnet-4-5' => [
                        'input_per_million' => (float) env('ANTHROPIC_SONNET_45_INPUT_PER_MILLION', 3.00),
                        'output_per_million' => (float) env('ANTHROPIC_SONNET_45_OUTPUT_PER_MILLION', 15.00),
                    ],
                    'claude-sonnet-4-6' => [
                        'input_per_million' => (float) env('ANTHROPIC_SONNET_46_INPUT_PER_MILLION', 3.00),
                        'output_per_million' => (float) env('ANTHROPIC_SONNET_46_OUTPUT_PER_MILLION', 15.00),
                    ],
                    'claude-opus-4' => [
                        'input_per_million' => (float) env('ANTHROPIC_OPUS_4_INPUT_PER_MILLION', 15.00),
                        'output_per_million' => (float) env('ANTHROPIC_OPUS_4_OUTPUT_PER_MILLION', 75.00),
                    ],
                    'claude-opus-4-1' => [
                        'input_per_million' => (float) env('ANTHROPIC_OPUS_41_INPUT_PER_MILLION', 15.00),
                        'output_per_million' => (float) env('ANTHROPIC_OPUS_41_OUTPUT_PER_MILLION', 75.00),
                    ],
                    'claude-opus-4-6' => [
                        'input_per_million' => (float) env('ANTHROPIC_OPUS_46_INPUT_PER_MILLION', 15.00),
                        'output_per_million' => (float) env('ANTHROPIC_OPUS_46_OUTPUT_PER_MILLION', 75.00),
                    ],
                    'claude-opus-4-7' => [
                        'input_per_million' => (float) env('ANTHROPIC_OPUS_47_INPUT_PER_MILLION', 15.00),
                        'output_per_million' => (float) env('ANTHROPIC_OPUS_47_OUTPUT_PER_MILLION', 75.00),
                    ],
                ],
            ],
        ],

        'openai' => [
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'pricing' => [
                'default' => [
                    'input_per_million' => (float) env('OPENAI_DEFAULT_INPUT_PER_MILLION', 0.00),
                    'output_per_million' => (float) env('OPENAI_DEFAULT_OUTPUT_PER_MILLION', 0.00),
                ],
                'models' => [
                    'gpt-4o' => [
                        'input_per_million' => (float) env('OPENAI_GPT_4O_INPUT_PER_MILLION', 5.00),
                        'output_per_million' => (float) env('OPENAI_GPT_4O_OUTPUT_PER_MILLION', 15.00),
                    ],
                    'gpt-4o-mini' => [
                        'input_per_million' => (float) env('OPENAI_GPT_4O_MINI_INPUT_PER_MILLION', 0.15),
                        'output_per_million' => (float) env('OPENAI_GPT_4O_MINI_OUTPUT_PER_MILLION', 0.60),
                    ],
                    'gpt-4.1' => [
                        'input_per_million' => (float) env('OPENAI_GPT_41_INPUT_PER_MILLION', 2.00),
                        'output_per_million' => (float) env('OPENAI_GPT_41_OUTPUT_PER_MILLION', 8.00),
                    ],
                    'gpt-4.1-mini' => [
                        'input_per_million' => (float) env('OPENAI_GPT_41_MINI_INPUT_PER_MILLION', 0.40),
                        'output_per_million' => (float) env('OPENAI_GPT_41_MINI_OUTPUT_PER_MILLION', 1.60),
                    ],
                ],
            ],
        ],

        'gemini' => [
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],

        'grok' => [
            'base_url' => env('GROK_BASE_URL', 'https://api.x.ai/v1'),
        ],

        'lm-studio' => [
            'server_url' => env('LMSTUDIO_SERVER_URL', 'http://localhost:1234'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Raw Provider Exchange Logging
    |--------------------------------------------------------------------------
    |
    | Captures the verbatim request and response bytes of every laravel/ai
    | HTTP call into the ai_provider_exchanges table. `providers` is a comma-
    | separated allow-list of AiSystem provider values (or "all"); only those
    | providers are captured. Rows older than `retention_days` are removed by
    | the ai:prune-provider-exchanges command (scheduled daily).
    |
    */

    'raw_exchanges' => [
        'enabled' => env('CODE_TALKER_RAW_EXCHANGES_ENABLED', true),
        'providers' => env('CODE_TALKER_RAW_EXCHANGES_PROVIDERS', 'lm-studio'),
        'retention_days' => (int) env('CODE_TALKER_RAW_EXCHANGES_RETENTION_DAYS', 14),
    ],

];

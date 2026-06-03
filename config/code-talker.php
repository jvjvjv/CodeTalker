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
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Default configuration for each AI provider. The AiSystem database records
    | override these at runtime — these are the fallback defaults used when
    | services are instantiated without explicit parameters.
    |
    */

    'providers' => [

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 1024),
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
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 1024),
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
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'max_tokens' => (int) env('GEMINI_MAX_TOKENS', 1024),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],

        'grok' => [
            'model' => env('GROK_MODEL', 'grok-3-mini'),
            'max_tokens' => (int) env('GROK_MAX_TOKENS', 1024),
            'base_url' => env('GROK_BASE_URL', 'https://api.x.ai/v1'),
        ],

        'lm-studio' => [
            'server_url' => env('LMSTUDIO_SERVER_URL', 'http://localhost:1234'),
            'model' => env('LMSTUDIO_MODEL', ''),
            'max_tokens' => (int) env('LMSTUDIO_MAX_TOKENS', 1024),
        ],

    ],

];

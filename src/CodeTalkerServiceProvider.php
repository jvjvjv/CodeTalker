<?php

namespace Jvjvjv\CodeTalker;

use Jvjvjv\CodeTalker\Console\Commands\BackfillAiSystemCapabilitiesCommand;
use Jvjvjv\CodeTalker\Console\Commands\BackfillConversationUsageCommand;
use Jvjvjv\CodeTalker\Console\Commands\CompleteIdleConversationsCommand;
use Jvjvjv\CodeTalker\Console\Commands\PruneProviderExchangesCommand;
use Jvjvjv\CodeTalker\Console\Commands\ReadProviderExchangeCommand;
use Jvjvjv\CodeTalker\Console\Commands\SyncConversationUsageCommand;
use Jvjvjv\CodeTalker\Jobs\BackfillConversationUsageJob;
use Jvjvjv\CodeTalker\Mcp\Servers\CodeTalkerServer;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Observers\AiConversationObserver;
use Jvjvjv\CodeTalker\Services\LaravelAi\AgentFactory;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\ProviderModelsClient;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeContext;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeRecorder;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

class CodeTalkerServiceProvider extends ServiceProvider
{
    /**
     * Additional tool directories registered by host apps: path => namespace prefix.
     *
     * @var array<string, string>
     */
    protected static array $toolDirectories = [];

    /**
     * Callbacks that resolve extra tool parameter overrides per conversation.
     *
     * @var array<callable>
     */
    protected static array $toolParameterResolvers = [];

    /**
     * Register a directory containing laravel/mcp Tool classes (or, for backward
     * compatibility, legacy AiToolHandlerContract implementations).
     *
     * Call this in your AppServiceProvider::register() or boot() method.
     * Tools in this directory will be auto-discovered alongside the package's own tools.
     *
     * Example:
     *   CodeTalkerServiceProvider::addToolDirectory(
     *       app_path('Services/Mcp/Tools'),
     *       'App\\Services\\Mcp\\Tools\\'
     *   );
     */
    public static function addToolDirectory(string $path, string $namespacePrefix): void
    {
        static::$toolDirectories[$path] = $namespacePrefix;
    }

    /**
     * @return array<string, string>
     */
    public static function toolDirectories(): array
    {
        return static::$toolDirectories;
    }

    /**
     * Register a callable that returns additional parameter overrides for tool handlers.
     * Called once per ChatBotToolRegistry instantiation.
     *
     * The callable receives the AiConversation and should return an array of
     * parameter name => value pairs that will be available via app()->makeWith().
     *
     * Example:
     *   CodeTalkerServiceProvider::registerToolParameterResolver(
     *       fn (AiConversation $conversation): array => [
     *           'resumeDataService' => app(ResumeDataServiceContract::class),
    *           'myDomainService' => app(MyDomainService::class),
     *       ]
     *   );
     */
    public static function registerToolParameterResolver(callable $resolver): void
    {
        static::$toolParameterResolvers[] = $resolver;
    }

    /**
     * @return array<string, mixed>
     */
    public static function resolveToolParameterOverrides(AiConversation $conversation): array
    {
        $overrides = [];

        foreach (static::$toolParameterResolvers as $resolver) {
            $overrides = array_merge($overrides, $resolver($conversation));
        }

        return $overrides;
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/code-talker.php', 'code-talker');

        $this->app->singleton(AiSystemProviderConfigurator::class);
        $this->app->singleton(AgentFactory::class);
        $this->app->singleton(ProviderModelsClient::class);
        $this->app->singleton(RawExchangeContext::class);
        $this->app->singleton(RawExchangeRecorder::class);

        // Default ToolContext used when a tool is resolved outside the local chat
        // loop — primarily the external MCP server, where the authenticated caller
        // (not a conversation) supplies the user identity. The local loop overrides
        // this by passing its own ToolContext via makeWith().
        $this->app->bind(ToolContext::class, static function ($app): ToolContext {
            $user = $app['auth']->user();

            return ToolContext::forUser($user?->getAuthIdentifier());
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->app->make(RawExchangeRecorder::class)->register();

        // Replace laravel/ai's built-in openai-compatible driver with one whose
        // gateway also streams provider reasoning ("thinking"). extend() takes
        // precedence over the built-in createOpenaiCompatibleDriver, so every
        // openai-compatible / lm-studio AiSystem gets it transparently.
        $this->app->make(\Laravel\Ai\AiManager::class)->extend(
            'openai-compatible',
            fn ($app, array $config) => new \Jvjvjv\CodeTalker\Services\LaravelAi\ReasoningOpenAiCompatibleProvider(
                $config,
                $app->make(\Illuminate\Contracts\Events\Dispatcher::class),
            ),
        );

        // Defer route registration until after all service providers (including the host
        // app's RouteServiceProvider) have booted. This ensures specific literal routes
        // like /login are registered before the root-level /{aiChatBot} wildcard.
        // The Route::has() guards skip registration if the host app has already loaded
        // its own versions of these route files.
        $this->app->booted(function (): void {
            if (! Route::has('chat-bots.root.show')) {
                $this->loadRoutesFrom($this->routeFilePath('codetalker-chatbots.php'));
            }

            if (! Route::has('admin.ai.index')) {
                $this->loadRoutesFrom($this->routeFilePath('codetalker-admin.php'));
            }

            $this->registerMcpServers();
        });

        AiConversation::observe(AiConversationObserver::class);

        // Map package model classes to the host app's Database\Factories\ directory.
        // This allows tests to use factory() on package models without factories needing
        // to live inside the package itself.
        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            if (str_starts_with($modelName, 'Jvjvjv\\CodeTalker\\Models\\')) {
                return 'Database\\Factories\\' . class_basename($modelName) . 'Factory';
            }

            // Fall back to Laravel's default convention for non-package models.
            $appNamespace = app()->getNamespace();
            $modelBasename = str_replace($appNamespace . 'Models\\', '', $modelName);

            return 'Database\\Factories\\' . $modelBasename . 'Factory';
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/code-talker.php' => config_path('code-talker.php'),
            ], 'code-talker-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'code-talker-migrations');

            $this->publishes([
                __DIR__ . '/../routes/codetalker-chatbots.php' => base_path('routes/codetalker-chatbots.php'),
                __DIR__ . '/../routes/codetalker-admin.php' => base_path('routes/codetalker-admin.php'),
            ], 'code-talker-routes');

            $this->publishes([
                __DIR__ . '/../routes/codetalker-chatbots.php' => base_path('routes/codetalker-chatbots.php'),
            ], 'code-talker-chatbot-routes');

            $this->publishes([
                __DIR__ . '/../routes/codetalker-admin.php' => base_path('routes/codetalker-admin.php'),
            ], 'code-talker-admin-routes');

            // TypeScript declarations for the Inertia props and stream events.
            // These track the package, so they are safe to re-publish on upgrade.
            $this->publishes([
                __DIR__ . '/../resources/js/types/code-talker.d.ts' => resource_path('js/types/code-talker.d.ts'),
            ], 'code-talker-types');

            // The stream client, plus the declarations it imports — publishing
            // the client alone would leave that relative import dangling.
            // Unlike the types, this is a starting point the host then owns.
            $this->publishes([
                __DIR__ . '/../resources/js/code-talker-stream.ts' => resource_path('js/code-talker-stream.ts'),
                __DIR__ . '/../resources/js/types/code-talker.d.ts' => resource_path('js/types/code-talker.d.ts'),
            ], 'code-talker-client');

            $this->commands([
                BackfillAiSystemCapabilitiesCommand::class,
                BackfillConversationUsageCommand::class,
                SyncConversationUsageCommand::class,
                PruneProviderExchangesCommand::class,
                CompleteIdleConversationsCommand::class,
                ReadProviderExchangeCommand::class,
            ]);
        }

        if (config('code-talker.schedule', true)) {
            Schedule::command('ai:sync-conversation-usage')
                ->twiceDaily(0, 12)
                ->withoutOverlapping();

            Schedule::job(new BackfillConversationUsageJob(false, 500))
                ->dailyAt('02:30')
                ->name('ai:daily-conversation-usage-backfill')
                ->withoutOverlapping();

            Schedule::command('ai:prune-provider-exchanges')
                ->dailyAt('03:00')
                ->withoutOverlapping();

            Schedule::command('ai:complete-idle-conversations')
                ->everyFifteenMinutes()
                ->withoutOverlapping();
        }
    }

    /**
     * Register the external MCP server (web and/or local transport) when enabled.
     *
     * Guarded so it is a no-op unless the host app opts in via config. The web
     * transport applies the configured auth middleware; the authenticated user
     * is mapped to a ToolContext by the container binding in register().
     */
    protected function registerMcpServers(): void
    {
        if (! config('code-talker.mcp.enabled', false)) {
            return;
        }

        if (config('code-talker.mcp.web.enabled', true)) {
            Mcp::web((string) config('code-talker.mcp.web.path', 'mcp/code-talker'), CodeTalkerServer::class)
                ->middleware((array) config('code-talker.mcp.web.middleware', []));
        }

        if (config('code-talker.mcp.local.enabled', false)) {
            Mcp::local((string) config('code-talker.mcp.local.handle', 'code-talker'), CodeTalkerServer::class);
        }
    }

    protected function routeFilePath(string $filename): string
    {
        $publishedPath = base_path('routes/' . $filename);

        if (is_file($publishedPath)) {
            return $publishedPath;
        }

        return __DIR__ . '/../routes/' . $filename;
    }
}

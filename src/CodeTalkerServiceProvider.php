<?php

namespace Jvjvjv\CodeTalker;

use Jvjvjv\CodeTalker\Console\Commands\BackfillAiSystemCapabilitiesCommand;
use Jvjvjv\CodeTalker\Console\Commands\BackfillConversationUsageCommand;
use Jvjvjv\CodeTalker\Console\Commands\SyncConversationUsageCommand;
use Jvjvjv\CodeTalker\Jobs\BackfillConversationUsageJob;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Observers\AiConversationObserver;
use Jvjvjv\CodeTalker\Services\AiClientFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

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
     * Register a directory containing AiToolHandlerContract implementations.
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

        $this->app->singleton(AiClientFactory::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

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

            $this->commands([
                BackfillAiSystemCapabilitiesCommand::class,
                BackfillConversationUsageCommand::class,
                SyncConversationUsageCommand::class,
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

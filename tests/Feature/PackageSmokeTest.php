<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Jvjvjv\CodeTalker\Services\LaravelAi\AgentFactory;
use Jvjvjv\CodeTalker\Services\Management\AiPersonaManager;
use Jvjvjv\CodeTalker\Services\Management\AiConversationManager;
use Jvjvjv\CodeTalker\Services\Management\AiMemoryManager;
use Jvjvjv\CodeTalker\Services\Management\AiSystemManager;
use Jvjvjv\CodeTalker\Services\Management\AiSystemPromptManager;
use Jvjvjv\CodeTalker\Tests\TestCase;

class PackageSmokeTest extends TestCase
{
    /**
     * The package is a library now: hosts own their routes entirely.
     */
    public function test_it_registers_no_routes(): void
    {
        $packageRoutes = collect(Route::getRoutes()->getRoutesByName())
            ->keys()
            ->filter(fn (string $name): bool => str_starts_with($name, 'chat-bots.')
                || str_starts_with($name, 'admin.ai.'));

        $this->assertCount(0, $packageRoutes);
    }

    /**
     * Management is a service concern now; the package registers no admin
     * routes and renders no admin pages.
     */
    public function test_it_registers_no_admin_routes(): void
    {
        $adminRoutes = collect(Route::getRoutes()->getRoutesByName())
            ->keys()
            ->filter(fn (string $name): bool => str_starts_with($name, 'admin.ai.'));

        $this->assertCount(0, $adminRoutes);
    }

    public function test_it_registers_the_agent_factory_singleton(): void
    {
        $first = $this->app->make(AgentFactory::class);
        $second = $this->app->make(AgentFactory::class);

        $this->assertInstanceOf(AgentFactory::class, $first);
        $this->assertSame($first, $second);
    }

    /**
     * Every manager has to be container-resolvable, since that is how a host app
     * gets one — none of them are bound explicitly.
     */
    public function test_the_management_services_resolve_from_the_container(): void
    {
        foreach ([
            AiSystemManager::class,
            AiSystemPromptManager::class,
            AiPersonaManager::class,
            AiConversationManager::class,
            AiMemoryManager::class,
        ] as $manager) {
            $this->assertInstanceOf($manager, $this->app->make($manager));
        }
    }

    public function test_the_route_publish_tags_are_gone(): void
    {
        foreach (['code-talker-routes', 'code-talker-chatbot-routes', 'code-talker-admin-routes'] as $tag) {
            $this->assertSame([], ServiceProvider::pathsToPublish(
                provider: \Jvjvjv\CodeTalker\CodeTalkerServiceProvider::class,
                group: $tag,
            ), "Publish tag [{$tag}] should no longer exist.");
        }
    }

    /**
     * The frontend contract survives the HTTP removal as an opt-in helper.
     */
    public function test_the_frontend_publish_tags_survive(): void
    {
        foreach (['code-talker-types', 'code-talker-client'] as $tag) {
            $this->assertNotEmpty(ServiceProvider::pathsToPublish(
                provider: \Jvjvjv\CodeTalker\CodeTalkerServiceProvider::class,
                group: $tag,
            ), "Publish tag [{$tag}] should still exist.");
        }
    }
}

<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Inertia\Inertia;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Jvjvjv\CodeTalker\Services\LaravelAi\AgentFactory;
use Jvjvjv\CodeTalker\Services\Management\AiChatBotManager;
use Jvjvjv\CodeTalker\Services\Management\AiConversationManager;
use Jvjvjv\CodeTalker\Services\Management\AiMemoryManager;
use Jvjvjv\CodeTalker\Services\Management\AiSystemManager;
use Jvjvjv\CodeTalker\Services\Management\AiSystemPromptManager;
use Jvjvjv\CodeTalker\Tests\TestCase;

class PackageSmokeTest extends TestCase
{
    public function test_it_registers_the_expected_package_routes(): void
    {
        $this->assertTrue(Route::has('chat-bots.index'));
        $this->assertTrue(Route::has('chat-bots.chat.show'));
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

    public function test_inertia_is_available_to_package_controllers(): void
    {
        $this->assertTrue(class_exists(Inertia::class));
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
            AiChatBotManager::class,
            AiConversationManager::class,
            AiMemoryManager::class,
        ] as $manager) {
            $this->assertInstanceOf($manager, $this->app->make($manager));
        }
    }

    public function test_it_registers_publishable_route_files(): void
    {
        $publishGroups = ServiceProvider::pathsToPublish(
            provider: \Jvjvjv\CodeTalker\CodeTalkerServiceProvider::class,
            group: 'code-talker-routes',
        );

        $this->assertContains(base_path('routes/codetalker-chatbots.php'), $publishGroups);

        $publishedSources = array_flip($publishGroups);

        $this->assertStringEndsWith(
            '/routes/codetalker-chatbots.php',
            $publishedSources[base_path('routes/codetalker-chatbots.php')],
        );
    }

    public function test_the_admin_route_publish_tag_is_gone(): void
    {
        $this->assertSame([], ServiceProvider::pathsToPublish(
            provider: \Jvjvjv\CodeTalker\CodeTalkerServiceProvider::class,
            group: 'code-talker-admin-routes',
        ));
    }
}

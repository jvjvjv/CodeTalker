<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Inertia\Inertia;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Jvjvjv\CodeTalker\Services\AiClientFactory;
use Jvjvjv\CodeTalker\Tests\TestCase;

class PackageSmokeTest extends TestCase
{
    public function test_it_registers_the_expected_package_routes(): void
    {
        $this->assertTrue(Route::has('chat-bots.index'));
        $this->assertTrue(Route::has('chat-bots.chat.show'));
        $this->assertTrue(Route::has('admin.ai.systems.index'));
    }

    public function test_it_registers_the_ai_client_factory_singleton(): void
    {
        $first = $this->app->make(AiClientFactory::class);
        $second = $this->app->make(AiClientFactory::class);

        $this->assertInstanceOf(AiClientFactory::class, $first);
        $this->assertSame($first, $second);
    }

    public function test_inertia_is_available_to_package_controllers(): void
    {
        $this->assertTrue(class_exists(Inertia::class));
    }

    public function test_it_registers_publishable_route_files(): void
    {
        $publishGroups = ServiceProvider::pathsToPublish(
            provider: \Jvjvjv\CodeTalker\CodeTalkerServiceProvider::class,
            group: 'code-talker-routes',
        );

        $this->assertContains(base_path('routes/codetalker-chatbots.php'), $publishGroups);
        $this->assertContains(base_path('routes/codetalker-admin.php'), $publishGroups);

        $publishedSources = array_flip($publishGroups);

        $this->assertStringEndsWith(
            '/routes/codetalker-chatbots.php',
            $publishedSources[base_path('routes/codetalker-chatbots.php')],
        );

        $this->assertStringEndsWith(
            '/routes/codetalker-admin.php',
            $publishedSources[base_path('routes/codetalker-admin.php')],
        );
    }
}
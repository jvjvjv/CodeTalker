<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Jvjvjv\CodeTalker\CodeTalkerServiceProvider;
use Jvjvjv\CodeTalker\Mcp\Servers\CodeTalkerServer;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\FetchWebPageTool;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\GetTemporalInformationTool;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\HttpRequestTool;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\ScanMemoriesTool;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWebTool;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Jvjvjv\CodeTalker\Tests\TestCase;
use ReflectionProperty;

class CodeTalkerServerToolsTest extends TestCase
{
    /**
     * The external MCP transport exposes the same tool classes the local chat
     * loop runs. `http-request` is registered in full, write methods included:
     * that transport is authenticated and off by default, so its caller is a
     * principal the host chose to admit.
     */
    public function testItExposesEveryChatBotToolToExternalMcpClients(): void
    {
        $tools = new ReflectionProperty(CodeTalkerServer::class, 'tools');

        $this->assertEqualsCanonicalizing(
            [
                FetchWebPageTool::class,
                HttpRequestTool::class,
                GetTemporalInformationTool::class,
                SearchWebTool::class,
                ScanMemoriesTool::class,
            ],
            $tools->getDefaultValue(),
        );
    }

    /**
     * Neither new tool reads user-scoped data, so neither gates on identity the
     * way ScanMemoriesTool does — they must list for an anonymous MCP caller.
     */
    public function testTheNewToolsRegisterWithoutAUserIdentity(): void
    {
        $this->assertTrue((new HttpRequestTool(new ToolContext()))->eligibleForRegistration());
        $this->assertTrue((new GetTemporalInformationTool())->eligibleForRegistration());

        // The contrast: ScanMemoriesTool does gate, and hides from an anonymous caller.
        $this->assertFalse((new ScanMemoriesTool(new ToolContext()))->eligibleForRegistration());
    }

    /**
     * A host whose published config predates `mcp.web.middleware` still gets
     * an authenticated route: the caller is what scopes the tools' user data.
     */
    public function testTheWebTransportIsAuthenticatedWhenTheMiddlewareKeyIsMissing(): void
    {
        config(['code-talker.mcp.enabled' => true, 'code-talker.mcp.web.path' => 'mcp/defaulted']);
        $web = config('code-talker.mcp.web');
        config(['code-talker.mcp.web' => Arr::except($web, 'middleware')]);

        $this->registerMcpServers();

        $this->assertContains('auth:sanctum', $this->routeAt('mcp/defaulted')->middleware());
    }

    public function testAnExplicitMiddlewareListIsHonoured(): void
    {
        config([
            'code-talker.mcp.enabled' => true,
            'code-talker.mcp.web.path' => 'mcp/custom',
            'code-talker.mcp.web.middleware' => ['auth:api'],
        ]);

        $this->registerMcpServers();

        $middleware = $this->routeAt('mcp/custom')->middleware();
        $this->assertContains('auth:api', $middleware);
        $this->assertNotContains('auth:sanctum', $middleware);
    }

    private function registerMcpServers(): void
    {
        $provider = new CodeTalkerServiceProvider($this->app);
        (fn () => $this->registerMcpServers())->call($provider);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function routeAt(string $uri): Route
    {
        $route = collect($this->app['router']->getRoutes()->getRoutes())
            ->first(fn (Route $route): bool => $route->uri() === $uri && in_array('POST', $route->methods(), true));

        $this->assertNotNull($route, "No POST route registered at {$uri}.");

        return $route;
    }
}

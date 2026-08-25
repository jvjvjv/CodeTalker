<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

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
}

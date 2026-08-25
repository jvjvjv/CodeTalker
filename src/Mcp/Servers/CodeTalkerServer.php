<?php

namespace Jvjvjv\CodeTalker\Mcp\Servers;

use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\FetchWebPageTool;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\GetTemporalInformationTool;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\HttpRequestTool;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\ScanMemoriesTool;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWebTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Code Talker')]
#[Version('1.0.0')]
#[Instructions('Exposes the Code Talker chat-bot tools — web fetching, general HTTP requests, multi-engine web search, per-user memory recall, and the current date and time.')]
class CodeTalkerServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * These are the same laravel/mcp Tool classes the local chat loop runs.
     * When invoked here they resolve their ToolContext from the authenticated
     * caller rather than a conversation.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        FetchWebPageTool::class,
        HttpRequestTool::class,
        SearchWebTool::class,
        ScanMemoriesTool::class,
        GetTemporalInformationTool::class,
    ];
}

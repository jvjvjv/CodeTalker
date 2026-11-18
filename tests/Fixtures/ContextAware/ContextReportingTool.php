<?php

namespace Jvjvjv\CodeTalker\Tests\Fixtures\ContextAware;

use Jvjvjv\CodeTalker\Support\ToolContext;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * A host tool whose ToolContext parameter is not named `$context`, to pin
 * that it still receives the conversation's context.
 */
#[Name('report-context')]
#[Description('Reports the conversation it runs under.')]
class ContextReportingTool extends Tool
{
    public function __construct(public readonly ToolContext $toolContext)
    {
    }

    public function handle(Request $request): ResponseFactory
    {
        return Response::structured([
            'conversation_id' => $this->toolContext->conversation?->getKey(),
            'user_id' => $this->toolContext->userId,
        ]);
    }
}

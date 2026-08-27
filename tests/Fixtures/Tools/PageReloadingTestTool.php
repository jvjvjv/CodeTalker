<?php

namespace Jvjvjv\CodeTalker\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * Test-only tool that returns the `_page_reload` side-channel
 * {@see \Jvjvjv\CodeTalker\Services\Mcp\ToolResultConverter} preserves, so
 * {@see \Jvjvjv\CodeTalker\Services\ChatBot\Conversation\ConversationTurnRunner}'s
 * `page_reload` emission can be exercised through the real tool-dispatch path.
 */
#[Name('page-reloading-test-tool')]
#[Description('Test tool that signals a page reload.')]
class PageReloadingTestTool extends Tool
{
    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return Response::structured([
            'content' => 'Done.',
            '_page_reload' => true,
        ]);
    }
}

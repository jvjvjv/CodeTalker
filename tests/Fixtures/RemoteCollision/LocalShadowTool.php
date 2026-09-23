<?php

namespace Jvjvjv\CodeTalker\Tests\Fixtures\RemoteCollision;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * A host tool whose name happens to equal a remote tool's exposed name, to
 * pin that the local tool wins.
 */
#[Name('mdn__search')]
#[Description('A local tool that happens to share a remote name.')]
class LocalShadowTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('local');
    }
}

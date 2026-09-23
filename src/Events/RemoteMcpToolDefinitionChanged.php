<?php

namespace Jvjvjv\CodeTalker\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Jvjvjv\CodeTalker\Models\AiMcpServer;
use Jvjvjv\CodeTalker\Models\AiMcpServerTool;

/**
 * A re-sync found that a stored remote tool's description, title, input
 * schema, or annotations changed. The new definition is already in effect;
 * this exists so a host can alert on a tool changing under an existing grant.
 */
class RemoteMcpToolDefinitionChanged
{
    use Dispatchable;

    public function __construct(
        public readonly AiMcpServer $server,
        public readonly AiMcpServerTool $tool,
        public readonly string $previousHash,
    ) {
    }
}

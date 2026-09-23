<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Jvjvjv\CodeTalker\Contracts\Mcp\AiToolRegistryContract;
use Jvjvjv\CodeTalker\Services\Mcp\Remote\RemoteToolSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * BridgedTool's counterpart for a remote MCP tool.
 *
 * The difference is the schema: BridgedTool converts each property on its
 * own, which loses the root and so any `$ref` into root-level `$defs` — common
 * in remote schemas, and fatal to the request. This converts from the root.
 * Execution still routes through the registry, which contains failures.
 */
class RemoteBridgedTool implements Tool
{
    /**
     * @param array<string, mixed> $inputSchema Raw JSON Schema (input_schema) for the tool.
     */
    public function __construct(
        private readonly string $toolName,
        private readonly string $toolDescription,
        private readonly array $inputSchema,
        private readonly AiToolRegistryContract $registry,
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): Stringable|string
    {
        return $this->toolDescription;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return RemoteToolSchema::properties($this->inputSchema);
    }

    public function handle(Request $request): Stringable|string
    {
        return (string) json_encode($this->registry->dispatch($this->toolName, $request->all()));
    }
}

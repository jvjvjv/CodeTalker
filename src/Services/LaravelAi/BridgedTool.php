<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Deserializer;
use Illuminate\JsonSchema\Types\Type;
use Jvjvjv\CodeTalker\Contracts\Mcp\AiToolRegistryContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Adapts one ChatBotToolRegistry handler (a laravel/mcp Tool or a legacy
 * AiToolHandlerContract implementation) to laravel/ai's Tool contract so the
 * SDK's agentic loop can invoke it. Execution routes back through the
 * registry, which already normalizes both handler kinds.
 */
class BridgedTool implements Tool
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

    /**
     * Honored by laravel/ai's ToolNameResolver, preserving registry tool names.
     */
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
        $properties = $this->inputSchema['properties'] ?? [];
        $required = $this->inputSchema['required'] ?? [];

        $types = [];

        foreach ($properties as $name => $propertySchema) {
            $type = Deserializer::deserialize((array) $propertySchema);

            if (in_array($name, $required, true)) {
                $type->required();
            }

            $types[$name] = $type;
        }

        return $types;
    }

    public function handle(Request $request): Stringable|string
    {
        return (string) json_encode($this->registry->dispatch($this->toolName, $request->all()));
    }
}

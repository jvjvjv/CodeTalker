<?php

namespace Jvjvjv\CodeTalker\Services\Mcp;

use Jvjvjv\CodeTalker\CodeTalkerServiceProvider;
use Jvjvjv\CodeTalker\Contracts\Mcp\AiToolHandlerContract;
use Jvjvjv\CodeTalker\Contracts\Mcp\AiToolRegistryContract;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Services\AiMemoryService;

class ChatBotToolRegistry implements AiToolRegistryContract
{
    use DiscoversAiToolHandlers;

    /** @var array<string, AiToolHandlerContract> */
    private array $handlers = [];

    /**
     * @param array<string>|null $allowedToolNames  null = no tools; [] = no tools; non-empty = filter by name
     * @param array<string, mixed> $extraParameterOverrides  Additional container make() overrides for tool handlers
     */
    public function __construct(
        AiConversation $conversation,
        ?array $allowedToolNames = null,
        bool $exposeAllDiscoveredTools = false,
        array $extraParameterOverrides = [],
    ) {
        $baseOverrides = [
            'conversation' => $conversation,
            'memoryService' => app(AiMemoryService::class),
            'userId' => $conversation->user_id,
        ];

        // Merge overrides resolved by host-app registered resolvers
        foreach (CodeTalkerServiceProvider::resolveToolParameterOverrides($conversation) as $key => $value) {
            $baseOverrides[$key] = $value;
        }

        $parameterOverrides = array_merge($baseOverrides, $extraParameterOverrides);

        // Package tools first, then host-app registered directories
        $toolDirectories = array_merge(
            [__DIR__ . '/Tools/ChatBot' => 'Jvjvjv\\CodeTalker\\Services\\Mcp\\Tools\\ChatBot\\'],
            CodeTalkerServiceProvider::toolDirectories(),
        );

        $handlers = $this->discoverHandlers(
            $toolDirectories,
            $parameterOverrides,
            ['Jvjvjv\\CodeTalker\\Services\\Mcp\\Tools\\ChatBot'],
        );

        if ($exposeAllDiscoveredTools) {
            $this->handlers = $handlers;

            return;
        }

        if ($allowedToolNames === null || $allowedToolNames === []) {
            $this->handlers = [];

            return;
        }

        $allowedToolNames = array_values(array_unique(array_map('strval', $allowedToolNames)));
        $allowedLookup = array_fill_keys($allowedToolNames, true);

        $this->handlers = array_filter(
            $handlers,
            static fn (AiToolHandlerContract $handler, string $name): bool => isset($allowedLookup[$name]),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return array<int, array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function toApiTools(): array
    {
        return array_values(array_map(
            static fn (AiToolHandlerContract $handler): array => [
                'name' => $handler->name(),
                'description' => $handler->description(),
                'input_schema' => $handler->schema(),
            ],
            $this->handlers,
        ));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function dispatch(string $toolName, array $input): array
    {
        if (!isset($this->handlers[$toolName])) {
            return ['error' => "Unknown tool: {$toolName}"];
        }

        return $this->handlers[$toolName]->handle($input);
    }
}

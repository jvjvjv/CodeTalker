<?php

namespace Jvjvjv\CodeTalker\Services\Mcp;

use Jvjvjv\CodeTalker\CodeTalkerServiceProvider;
use Jvjvjv\CodeTalker\Contracts\Mcp\AiToolHandlerContract;
use Jvjvjv\CodeTalker\Contracts\Mcp\AiToolRegistryContract;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Services\AiMemoryService;
use Illuminate\Support\Facades\Log;
use Jvjvjv\CodeTalker\Services\LaravelAi\BridgedTool;
use Jvjvjv\CodeTalker\Services\LaravelAi\RemoteBridgedTool;
use Jvjvjv\CodeTalker\Services\Mcp\Remote\RemoteToolCatalog;
use Jvjvjv\CodeTalker\Services\Mcp\Remote\RemoteToolHandler;
use Jvjvjv\CodeTalker\Services\Mcp\Remote\RemoteToolSchema;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

class ChatBotToolRegistry implements AiToolRegistryContract
{
    use DiscoversAiToolHandlers;

    /** @var array<string, Tool|AiToolHandlerContract|RemoteToolHandler> */
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
            // New canonical context for laravel/mcp Tool subclasses.
            'context' => ToolContext::forConversation($conversation),
            // Retained for backward compatibility with legacy AiToolHandlerContract tools.
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
            $this->handlers = $this->withRemoteHandlers($handlers, null);

            return;
        }

        if ($allowedToolNames === null || $allowedToolNames === []) {
            $this->handlers = [];

            return;
        }

        $allowedToolNames = array_values(array_unique(array_map('strval', $allowedToolNames)));
        $allowedLookup = array_fill_keys($allowedToolNames, true);

        $this->handlers = $this->withRemoteHandlers(
            array_filter(
                $handlers,
                static fn (object $handler, string $name): bool => isset($allowedLookup[$name]),
                ARRAY_FILTER_USE_BOTH,
            ),
            $allowedToolNames,
        );
    }

    /**
     * Add the granted remote MCP tools, read from the synced catalog. Local
     * tools win a name collision, extending discovery's first-wins rule.
     *
     * @param array<string, Tool|AiToolHandlerContract> $localHandlers
     * @param array<int, string>|null $allowedToolNames null = every exposable remote tool
     * @return array<string, Tool|AiToolHandlerContract|RemoteToolHandler>
     */
    private function withRemoteHandlers(array $localHandlers, ?array $allowedToolNames): array
    {
        $handlers = $localHandlers;

        foreach (app(RemoteToolCatalog::class)->handlers($allowedToolNames) as $name => $remoteHandler) {
            if (isset($handlers[$name])) {
                Log::warning('code-talker: a local tool shadows a remote MCP tool of the same name', [
                    'tool' => $name,
                ]);

                continue;
            }

            $handlers[$name] = $remoteHandler;
        }

        return $handlers;
    }

    /**
     * @return array<int, array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function toApiTools(): array
    {
        return array_values(array_map(
            static function (object $handler): array {
                if ($handler instanceof RemoteToolHandler) {
                    return [
                        'name' => $handler->name(),
                        'description' => $handler->description(),
                        'input_schema' => $handler->inputSchema(),
                    ];
                }

                if ($handler instanceof Tool) {
                    $serialized = $handler->toArray();

                    return [
                        'name' => $serialized['name'],
                        'description' => (string) ($serialized['description'] ?? ''),
                        'input_schema' => $serialized['inputSchema'] ?? ['type' => 'object', 'properties' => (object) []],
                    ];
                }

                /** @var AiToolHandlerContract $handler */
                return [
                    'name' => $handler->name(),
                    'description' => $handler->description(),
                    'input_schema' => $handler->schema(),
                ];
            },
            $this->handlers,
        ));
    }

    /**
     * The registered tools adapted to laravel/ai's Tool contract, for use in
     * a laravel/ai agent's tools() list.
     *
     * A remote tool whose schema fails to convert is left out rather than
     * offered with an empty schema. Sync already refuses such tools, so this
     * only guards against a schema that converted then and does not now.
     *
     * @return array<int, BridgedTool|RemoteBridgedTool>
     */
    public function toLaravelAiTools(): array
    {
        $tools = [];

        foreach ($this->toApiTools() as $tool) {
            if (!$this->handlers[$tool['name']] instanceof RemoteToolHandler) {
                $tools[] = new BridgedTool($tool['name'], $tool['description'], (array) $tool['input_schema'], $this);

                continue;
            }

            $reason = RemoteToolSchema::unrepresentableReason((array) $tool['input_schema']);

            if ($reason !== null) {
                Log::warning('code-talker: remote MCP tool withheld from this turn', [
                    'tool' => $tool['name'],
                    'reason' => $reason,
                ]);

                continue;
            }

            $tools[] = new RemoteBridgedTool($tool['name'], $tool['description'], (array) $tool['input_schema'], $this);
        }

        return $tools;
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

        $handler = $this->handlers[$toolName];

        // Contains its own failures: a remote outage returns an error result
        // instead of throwing through laravel/ai's loop and ending the turn.
        if ($handler instanceof RemoteToolHandler) {
            return $handler->handle($input);
        }

        if ($handler instanceof Tool) {
            return ToolResultConverter::toArray($handler->handle(new Request($input)));
        }

        return $handler->handle($input);
    }
}

<?php

namespace Jvjvjv\CodeTalker\Contracts\Mcp;

/**
 * Contract for AI chat bot tool handlers.
 *
 * @deprecated Tools should now extend {@see \Laravel\Mcp\Server\Tool} so the
 * same class runs in the local chat loop and can be exposed to external MCP
 * clients. This interface is still discovered and dispatched for backward
 * compatibility, but will be removed in a future major release. Migrate by:
 *   - extending \Laravel\Mcp\Server\Tool and adding a #[Description] (and
 *     optionally #[Name]) attribute instead of the name()/description() methods;
 *   - defining schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
 *     with the fluent builder instead of returning a raw array;
 *   - implementing handle(\Laravel\Mcp\Request $request): \Laravel\Mcp\Response
 *     and returning Response::structured([...]) / Response::error('...');
 *   - depending on {@see \Jvjvjv\CodeTalker\Support\ToolContext} for the user /
 *     conversation instead of injecting AiConversation directly.
 *
 * Tools are auto-discovered from registered tool directories (package and host
 * app). Register additional host-app directories via
 * CodeTalkerServiceProvider::addToolDirectory() in your AppServiceProvider.
 *
 * == Schema Format ==
 * The schema() method must return a JSON Schema (draft 2020-12) input_schema:
 *
 *     [
 *         'type' => 'object',
 *         'properties' => [
 *             'query' => ['type' => 'string', 'description' => 'The search query'],
 *         ],
 *         'required' => ['query'],
 *     ]
 */
interface AiToolHandlerContract
{
    /**
     * Unique snake_case tool name (e.g., 'web_search', 'get_site_info').
     */
    public function name(): string;

    /**
     * Human-readable description sent to the AI model explaining when to use this tool.
     */
    public function description(): string;

    /**
     * JSON Schema (input_schema) defining the tool's expected parameters.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * Execute the tool logic and return a result for the AI model.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function handle(array $input): array;
}

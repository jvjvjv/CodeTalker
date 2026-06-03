<?php

namespace Jvjvjv\CodeTalker\Contracts\Mcp;

/**
 * Contract for AI chat bot MCP tool handlers.
 *
 * Implement this interface to create a custom tool that the AI model
 * can invoke during a conversation. Tools are auto-discovered from
 * registered tool directories (package and host app).
 *
 * == Tool Discovery ==
 * Tools are discovered automatically. Register additional host-app directories
 * via CodeTalkerServiceProvider::addToolDirectory() in your AppServiceProvider.
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

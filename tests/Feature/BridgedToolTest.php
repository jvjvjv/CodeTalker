<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Contracts\Mcp\AiToolRegistryContract;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Services\LaravelAi\BridgedTool;
use Jvjvjv\CodeTalker\Services\Mcp\ChatBotToolRegistry;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;

class BridgedToolTest extends TestCase
{
    private function registry(array $allowedTools): ChatBotToolRegistry
    {
        $conversation = new AiConversation(['feature' => 'chat-bot:test']);

        return new ChatBotToolRegistry($conversation, $allowedTools);
    }

    public function test_registry_tools_bridge_with_names_and_descriptions_preserved(): void
    {
        $tools = $this->registry(['fetch-web-page', 'scan-memories'])->toLaravelAiTools();

        $byName = collect($tools)->keyBy(fn (BridgedTool $tool): string => $tool->name());

        $this->assertEqualsCanonicalizing(['fetch-web-page', 'scan-memories'], $byName->keys()->all());

        foreach ($tools as $tool) {
            $this->assertSame($tool->name(), ToolNameResolver::resolve($tool));
            $this->assertNotSame('', (string) $tool->description());
        }
    }

    public function test_schema_converts_raw_json_schema_to_type_map(): void
    {
        $registry = $this->createStub(AiToolRegistryContract::class);

        $tool = new BridgedTool('example', 'An example tool.', [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'The search query'],
                'limit' => ['type' => 'integer'],
            ],
            'required' => ['query'],
        ], $registry);

        $factory = new JsonSchemaTypeFactory();
        $types = $tool->schema($factory);

        $this->assertEqualsCanonicalizing(['query', 'limit'], array_keys($types));

        // Serialize the type map the same way laravel/ai does when building
        // the provider request, and assert the original schema survives.
        $serialized = $factory->object($types)->toArray();

        $this->assertSame('string', $serialized['properties']['query']['type']);
        $this->assertSame('The search query', $serialized['properties']['query']['description']);
        $this->assertSame('integer', $serialized['properties']['limit']['type']);
        $this->assertSame(['query'], $serialized['required']);
    }

    public function test_mcp_tool_schema_round_trips_through_the_bridge(): void
    {
        $tools = $this->registry(['scan-memories'])->toLaravelAiTools();

        $types = $tools[0]->schema(new JsonSchemaTypeFactory());

        $this->assertArrayHasKey('topics', $types);
    }

    public function test_handle_dispatches_through_the_registry_and_returns_json(): void
    {
        Http::fake([
            'https://example.com/page' => Http::response(
                '<html><head><title>Hi</title></head><body><p>Body text.</p></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $tools = $this->registry(['fetch-web-page'])->toLaravelAiTools();

        $result = json_decode((string) $tools[0]->handle(new Request(['url' => 'https://example.com/page'])), true);

        $this->assertSame('Hi', $result['title']);
        $this->assertStringContainsString('Body text.', $result['content']);
    }
}

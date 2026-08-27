<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Services\Mcp\ChatBotToolRegistry;
use Jvjvjv\CodeTalker\Tests\TestCase;

class ChatBotToolRegistryTest extends TestCase
{
    private function registry(?array $allowedTools): ChatBotToolRegistry
    {
        $conversation = new AiConversation(['feature' => 'persona:test']);

        return new ChatBotToolRegistry($conversation, $allowedTools);
    }

    public function test_to_api_tools_exposes_the_kebab_named_package_tools(): void
    {
        $tools = $this->registry(['fetch-web-page', 'http-request', 'get-temporal-information', 'search-web', 'scan-memories'])->toApiTools();

        $byName = collect($tools)->keyBy('name');

        $this->assertEqualsCanonicalizing(
            ['fetch-web-page', 'http-request', 'get-temporal-information', 'search-web', 'scan-memories'],
            $byName->keys()->all(),
        );

        foreach ($tools as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertNotSame('', $tool['description']);
            $this->assertArrayHasKey('input_schema', $tool);
            $this->assertSame('object', $tool['input_schema']['type']);
        }

        $this->assertArrayHasKey('url', $byName['fetch-web-page']['input_schema']['properties']);
        $this->assertArrayHasKey('query', $byName['search-web']['input_schema']['properties']);
        $this->assertArrayHasKey('topics', $byName['scan-memories']['input_schema']['properties']);
        $this->assertArrayHasKey('request_policy', $byName['http-request']['input_schema']['properties']);
        $this->assertArrayHasKey('timezone', $byName['get-temporal-information']['input_schema']['properties']);
    }

    /**
     * Tool discovery walks the ChatBot tools directory recursively, so any
     * collaborator class added in a subdirectory is visited too. This pins the
     * discovered tool set so a helper that accidentally extends Tool (or
     * implements the legacy contract) shows up here rather than in production.
     */
    public function test_discovery_finds_exactly_the_package_tools(): void
    {
        $conversation = new AiConversation(['feature' => 'persona:test']);

        $registry = new ChatBotToolRegistry(
            $conversation,
            allowedToolNames: null,
            exposeAllDiscoveredTools: true,
        );

        $this->assertEqualsCanonicalizing(
            ['fetch-web-page', 'http-request', 'get-temporal-information', 'search-web', 'scan-memories'],
            array_column($registry->toApiTools(), 'name'),
        );
    }

    public function test_allowed_tools_filters_the_exposed_tools(): void
    {
        $tools = $this->registry(['fetch-web-page'])->toApiTools();

        $this->assertSame(['fetch-web-page'], array_column($tools, 'name'));
    }

    public function test_null_or_empty_allowed_tools_exposes_nothing(): void
    {
        $this->assertSame([], $this->registry(null)->toApiTools());
        $this->assertSame([], $this->registry([])->toApiTools());
    }

    public function test_dispatch_returns_the_normalized_array_the_loop_consumes(): void
    {
        Http::fake([
            'https://example.com/page' => Http::response(
                '<html><head><title>Hi</title></head><body><p>Body text.</p></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $result = $this->registry(['fetch-web-page'])->dispatch('fetch-web-page', [
            'url' => 'https://example.com/page',
        ]);

        $this->assertSame('https://example.com/page', $result['url']);
        $this->assertSame('Hi', $result['title']);
        $this->assertStringContainsString('Body text.', $result['content']);
    }

    public function test_dispatch_reports_unknown_tools(): void
    {
        $result = $this->registry(['fetch-web-page'])->dispatch('does-not-exist', []);

        $this->assertSame('Unknown tool: does-not-exist', $result['error']);
    }
}

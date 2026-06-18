<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWebTool;
use Jvjvjv\CodeTalker\Tests\TestCase;

class SearchWebToolTest extends TestCase
{
    public function test_it_requires_query(): void
    {
        $tool = new SearchWebTool(new AiConversation());

        $result = $tool->handle([]);

        $this->assertSame('A non-empty query is required.', $result['error']);
    }

    public function test_it_returns_markdown_results_for_all_supported_engines(): void
    {
        Http::fake([
            'https://www.bing.com/search*' => Http::response('<html><body><li class="b_algo"><h2><a href="https://bing.example/item">Bing Item</a></h2><div class="b_caption"><p>Bing description</p></div></li></body></html>', 200, ['Content-Type' => 'text/html']),
            'https://www.google.com/search*' => Http::response('<html><body><div id="search"><a href="https://google.example/item"><h3>Google Item</h3></a></div></body></html>', 200, ['Content-Type' => 'text/html']),
            'https://duckduckgo.com/html/*' => Http::response('<html><body><div class="result"><a class="result__a" href="https://duck.example/item">Duck Item</a><div class="result__snippet">Duck description</div></div></body></html>', 200, ['Content-Type' => 'text/html']),
            'https://search.brave.com/search*' => Http::response('<html><body><div class="snippet"><a class="heading-serpresult" href="https://brave.example/item">Brave Item</a><div class="snippet-description">Brave description</div></div></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $tool = new SearchWebTool(new AiConversation());

        $result = $tool->handle([
            'query' => 'laravel package testing',
            'page' => 1,
            'per_engine_limit' => 3,
        ]);

        $this->assertSame('laravel package testing', $result['query']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(3, $result['per_engine_limit']);

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('bing', $result['results']);
        $this->assertArrayHasKey('google', $result['results']);
        $this->assertArrayHasKey('duckduckgo', $result['results']);
        $this->assertArrayHasKey('brave', $result['results']);

        $this->assertStringContainsString('[Bing Item](https://bing.example/item)', $result['markdown']);
        $this->assertStringContainsString('[Google Item](https://google.example/item)', $result['markdown']);
        $this->assertStringContainsString('[Duck Item](https://duck.example/item)', $result['markdown']);
        $this->assertStringContainsString('[Brave Item](https://brave.example/item)', $result['markdown']);
        $this->assertStringContainsString('To continue searching, run this tool again with:', $result['markdown']);

        $this->assertSame(2, $result['next_page_input']['page']);
        $this->assertSame('laravel package testing', $result['next_page_input']['query']);
    }
}

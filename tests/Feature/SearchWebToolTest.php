<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Services\Mcp\ToolResultConverter;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWebTool;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Laravel\Mcp\Request;

class SearchWebToolTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runTool(SearchWebTool $tool, array $input): array
    {
        return ToolResultConverter::toArray($tool->handle(new Request($input)));
    }

    public function test_it_requires_query(): void
    {
        $tool = new SearchWebTool(new ToolContext());

        $result = $this->runTool($tool, []);

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

        $tool = new SearchWebTool(new ToolContext());

        $result = $this->runTool($tool, [
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

    /**
     * @return array<string, \Closure|\GuzzleHttp\Promise\PromiseInterface>
     */
    private function fakeEngineResponses(): array
    {
        return [
            'https://www.bing.com/search*' => Http::response('<html><body><li class="b_algo"><h2><a href="https://bing.example/item">Bing Item</a></h2><div class="b_caption"><p>Bing description</p></div></li></body></html>', 200, ['Content-Type' => 'text/html']),
            'https://www.google.com/search*' => Http::response('<html><body><div id="search"><a href="https://google.example/item"><h3>Google Item</h3></a></div></body></html>', 200, ['Content-Type' => 'text/html']),
            'https://duckduckgo.com/html/*' => Http::response('<html><body><div class="result"><a class="result__a" href="https://duck.example/item">Duck Item</a><div class="result__snippet">Duck description</div></div></body></html>', 200, ['Content-Type' => 'text/html']),
            'https://search.brave.com/search*' => Http::response('<html><body><div class="snippet"><a class="heading-serpresult" href="https://brave.example/item">Brave Item</a><div class="snippet-description">Brave description</div></div></body></html>', 200, ['Content-Type' => 'text/html']),
        ];
    }

    /**
     * Characterization test: the full structured payload the model consumes,
     * pinned key-by-key so the SearchWeb decomposition cannot reshape it.
     */
    public function test_it_returns_the_exact_structured_payload(): void
    {
        Http::fake($this->fakeEngineResponses());

        $result = $this->runTool(new SearchWebTool(new ToolContext()), [
            'query' => 'laravel package testing',
            'page' => 1,
            'per_engine_limit' => 3,
        ]);

        $this->assertSame([
            'query',
            'page',
            'engines',
            'per_engine_limit',
            'results',
            'markdown',
            'next_page_input',
            'next_actions',
        ], array_keys($result));

        $this->assertSame(['bing', 'google', 'duckduckgo', 'brave'], $result['engines']);
        $this->assertSame(['bing', 'google', 'duckduckgo', 'brave'], array_keys($result['results']));

        $this->assertSame([
            'source' => 'html',
            'query_url' => 'https://duckduckgo.com/html/?q=laravel+package+testing&s=0',
            'results' => [
                [
                    'title' => 'Duck Item',
                    'url' => 'https://duck.example/item',
                    'description' => 'Duck description',
                ],
            ],
        ], $result['results']['duckduckgo']);

        $this->assertSame([
            'source' => 'html',
            'query_url' => 'https://www.bing.com/search?q=laravel+package+testing&count=3&first=1',
            'results' => [
                [
                    'title' => 'Bing Item',
                    'url' => 'https://bing.example/item',
                    // The Bing pattern does not reach a <p> nested inside
                    // b_caption, so the snippet is dropped. Pinned as-is.
                    'description' => null,
                ],
            ],
        ], $result['results']['bing']);

        $this->assertSame([
            'query' => 'laravel package testing',
            'page' => 2,
            'per_engine_limit' => 3,
            'engines' => ['bing', 'google', 'duckduckgo', 'brave'],
        ], $result['next_page_input']);

        $this->assertSame([
            'Continue searching with a refined query and optional engine subset.',
            'Ask the model to fetch and inspect a specific result URL on your behalf.',
        ], $result['next_actions']);
    }

    public function test_it_renders_the_exact_markdown_document(): void
    {
        Http::fake($this->fakeEngineResponses());

        $result = $this->runTool(new SearchWebTool(new ToolContext()), [
            'query' => 'laravel package testing',
            'page' => 1,
            'per_engine_limit' => 3,
            'engines' => ['duckduckgo'],
        ]);

        $this->assertSame(implode("\n", [
            '## Search results for "laravel package testing"',
            'Page: 1',
            '',
            '### DuckDuckGo (html)',
            '- Search URL: https://duckduckgo.com/html/?q=laravel+package+testing&s=0',
            '- [Duck Item](https://duck.example/item)',
            '  - Duck description',
            '',
            'To continue searching, run this tool again with:',
            '- `query`: laravel package testing',
            '- `page`: 2',
            '- `per_engine_limit`: 3',
            '- `engines`: duckduckgo',
            '',
            'Ask the model to fetch any specific link for deeper analysis.',
        ]), $result['markdown']);
    }

    public function test_a_failing_engine_is_isolated_from_the_others(): void
    {
        Http::fake(array_merge($this->fakeEngineResponses(), [
            'https://www.bing.com/search*' => fn () => throw new \RuntimeException('connection refused'),
        ]));

        $result = $this->runTool(new SearchWebTool(new ToolContext()), [
            'query' => 'laravel package testing',
            'per_engine_limit' => 3,
            'engines' => ['bing', 'duckduckgo'],
        ]);

        $this->assertSame([
            'results' => [],
            'error' => 'Search failed on bing: connection refused',
        ], $result['results']['bing']);

        // The healthy engine is unaffected.
        $this->assertCount(1, $result['results']['duckduckgo']['results']);

        $this->assertStringContainsString('### Bing (none)', $result['markdown']);
        $this->assertStringContainsString('- _Error_: Search failed on bing: connection refused', $result['markdown']);
    }

    public function test_an_http_failure_reports_the_status_without_throwing(): void
    {
        Http::fake([
            'https://duckduckgo.com/html/*' => Http::response('nope', 503),
        ]);

        $result = $this->runTool(new SearchWebTool(new ToolContext()), [
            'query' => 'laravel package testing',
            'engines' => ['duckduckgo'],
        ]);

        $this->assertSame('none', $result['results']['duckduckgo']['source']);
        $this->assertSame([], $result['results']['duckduckgo']['results']);
        $this->assertStringStartsWith(
            'Failed to fetch duckduckgo results (HTTP 503',
            $result['results']['duckduckgo']['error'],
        );
    }

    public function test_it_rejects_unsupported_engines(): void
    {
        $result = $this->runTool(new SearchWebTool(new ToolContext()), [
            'query' => 'anything',
            'engines' => ['bing', 'altavista'],
        ]);

        $this->assertSame(
            'Unsupported engines: altavista. Supported engines are: bing, google, duckduckgo, brave.',
            $result['error'],
        );
    }

    public function test_search_service_config_exposes_provider_defaults(): void {
        $config = require __DIR__ . '/../../config/code-talker.php';

        $this->assertSame('', $config['services']['brave']['search_api_key']);
        $this->assertSame('', $config['services']['bing']['search_api_key']);
        $this->assertSame('https://api.bing.microsoft.com/v7.0/search', $config['services']['bing']['endpoint']);
        $this->assertSame('', $config['services']['google']['search_api_key']);
        $this->assertSame('', $config['services']['google']['search_engine_id']);
    }

    public function test_engine_api_keys_switch_the_fetch_strategy(): void
    {
        config()->set('code-talker.services.brave.search_api_key', 'brave-key');

        Http::fake([
            'https://api.search.brave.com/*' => Http::response([
                'web' => [
                    'results' => [
                        ['title' => 'Brave API Item', 'url' => 'https://brave.example/api', 'description' => 'From the API'],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->runTool(new SearchWebTool(new ToolContext()), [
            'query' => 'laravel',
            'engines' => ['brave'],
            'per_engine_limit' => 2,
        ]);

        $this->assertSame('api', $result['results']['brave']['source']);
        $this->assertSame([
            [
                'title' => 'Brave API Item',
                'url' => 'https://brave.example/api',
                'description' => 'From the API',
            ],
        ], $result['results']['brave']['results']);
    }
}

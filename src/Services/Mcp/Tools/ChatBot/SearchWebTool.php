<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Jvjvjv\CodeTalker\Support\WebScraperUserAgent;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('search-web')]
#[Description('Search across Bing, Google, DuckDuckGo, and Brave. Returns normalized links, descriptions, and markdown-formatted results for easy follow-up.')]
class SearchWebTool extends Tool
{
    private const DEFAULT_PAGE = 1;

    private const MAX_PAGE = 20;

    private const SUPPORTED_ENGINES = ['bing', 'google', 'duckduckgo', 'brave'];

    public function __construct(
        private ToolContext $context,
    ) {}

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The search query to run.')
                ->min(1)
                ->required(),
            'engines' => $schema->array()
                ->items($schema->string()->enum(self::SUPPORTED_ENGINES))
                ->min(1)
                ->max(4)
                ->description('Subset of engines to use. Defaults to all supported engines.'),
            'page' => $schema->integer()
                ->description('Result page number (1-20). Increase to continue searching.')
                ->min(1)
                ->max(self::MAX_PAGE)
                ->default(self::DEFAULT_PAGE),
            'per_engine_limit' => $schema->integer()
                ->description('Maximum results to return per engine (1-10).')
                ->min(1)
                ->max(10)
                ->default(5),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $query = trim((string) $request->get('query', ''));

        if ($query === '') {
            return Response::error('A non-empty query is required.');
        }

        $enginesInput = array_values(array_unique(array_map(
            static fn ($engine): string => strtolower(trim((string) $engine)),
            (array) ($request->get('engines') ?? self::SUPPORTED_ENGINES),
        )));

        $engines = $enginesInput !== [] ? $enginesInput : self::SUPPORTED_ENGINES;
        $unsupported = array_values(array_diff($engines, self::SUPPORTED_ENGINES));

        if ($unsupported !== []) {
            return Response::error(sprintf(
                'Unsupported engines: %s. Supported engines are: %s.',
                implode(', ', $unsupported),
                implode(', ', self::SUPPORTED_ENGINES),
            ));
        }

        $limit = (int) ($request->get('per_engine_limit') ?? 5);
        $limit = max(1, min(10, $limit));
        $page = (int) ($request->get('page') ?? self::DEFAULT_PAGE);
        $page = max(1, min(self::MAX_PAGE, $page));

        $resultsByEngine = [];

        foreach ($engines as $engine) {
            try {
                $resultsByEngine[$engine] = $this->searchEngine($engine, $query, $limit, $page);
            } catch (\Throwable $e) {
                Log::warning('search-web engine search failed', [
                    'engine' => $engine,
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);

                $resultsByEngine[$engine] = [
                    'results' => [],
                    'error' => sprintf('Search failed on %s: %s', $engine, $e->getMessage()),
                ];
            }
        }

        return Response::structured([
            'query' => $query,
            'page' => $page,
            'engines' => $engines,
            'per_engine_limit' => $limit,
            'results' => $resultsByEngine,
            'markdown' => $this->renderMarkdown($query, $page, $limit, $engines, $resultsByEngine),
            'next_page_input' => [
                'query' => $query,
                'page' => min(self::MAX_PAGE, $page + 1),
                'per_engine_limit' => $limit,
                'engines' => $engines,
            ],
            'next_actions' => [
                'Continue searching with a refined query and optional engine subset.',
                'Ask the model to fetch and inspect a specific result URL on your behalf.',
            ],
        ]);
    }

    /**
     * @return array{results: array<int, array{title: string, url: string, description: string|null}>, source: string, error?: string}
     */
    private function searchEngine(string $engine, string $query, int $limit, int $page): array
    {
        return match ($engine) {
            'bing' => $this->searchBing($query, $limit, $page),
            'google' => $this->searchGoogle($query, $limit, $page),
            'duckduckgo' => $this->searchDuckDuckGo($query, $limit, $page),
            'brave' => $this->searchBrave($query, $limit, $page),
            default => ['results' => [], 'source' => 'none', 'error' => 'Unsupported engine.'],
        };
    }

    /**
     * @return array{results: array<int, array{title: string, url: string, description: string|null}>, source: string, error?: string}
     */
    private function searchDuckDuckGo(string $query, int $limit, int $page): array
    {
        $offset = max(0, ($page - 1) * $limit);
        $response = $this->webHttpClient()
            ->get('https://duckduckgo.com/html/', ['q' => $query, 's' => $offset]);

        if ($response->failed()) {
            return $this->httpErrorResult('duckduckgo', $response->status(), $response->reason());
        }

        $results = $this->parseEngineHtmlResults('duckduckgo', $response->body(), $limit);

        return [
            'source' => 'html',
            'query_url' => 'https://duckduckgo.com/html/?' . http_build_query(['q' => $query, 's' => $offset]),
            'results' => $this->limitResults($results, $limit),
        ];
    }

    /**
     * @return array{results: array<int, array{title: string, url: string, description: string|null}>, source: string, error?: string}
     */
    private function searchBing(string $query, int $limit, int $page): array
    {
        $offset = max(0, ($page - 1) * $limit);
        $apiKey = trim((string) config('services.bing.search_api_key', env('BING_SEARCH_API_KEY', '')));

        if ($apiKey !== '') {
            return $this->searchBingViaApi($query, $limit, $offset, $apiKey);
        }

        return $this->searchBingViaWeb($query, $limit, $offset);
    }

    /**
     * @return array{results: array<int, array{title: string, url: string, description: string|null}>, source: string, error?: string}
     */
    private function searchBingViaApi(string $query, int $limit, int $offset, string $apiKey): array
    {
        $endpoint = rtrim((string) config('services.bing.endpoint', env('BING_SEARCH_ENDPOINT', 'https://api.bing.microsoft.com/v7.0/search')), '/');
        $response = $this->apiHttpClient()
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $apiKey])
            ->get($endpoint, [
                'q' => $query,
                'count' => $limit,
                'offset' => $offset,
                'mkt' => 'en-US',
                'textDecorations' => 'false',
                'textFormat' => 'Raw',
            ]);

        if ($response->failed()) {
            return $this->httpErrorResult('bing', $response->status(), $response->reason());
        }

        $items = (array) data_get($response->json(), 'webPages.value', []);
        $results = array_map(static fn (array $item): array => [
            'title' => (string) ($item['name'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'description' => isset($item['snippet']) ? (string) $item['snippet'] : null,
        ], $items);

        return [
            'source' => 'api',
            'query_url' => $endpoint . '?' . http_build_query(['q' => $query, 'count' => $limit, 'offset' => $offset]),
            'results' => $this->limitResults($results, $limit),
        ];
    }

    /**
     * @return array{results: array<int, array{title: string, url: string, description: string|null}>, source: string, error?: string}
     */
    private function searchBingViaWeb(string $query, int $limit, int $offset): array
    {
        $response = $this->webHttpClient()
            ->get('https://www.bing.com/search', ['q' => $query, 'count' => $limit, 'first' => $offset + 1]);

        if ($response->failed()) {
            return $this->httpErrorResult('bing', $response->status(), $response->reason());
        }

        $results = $this->parseEngineHtmlResults('bing', $response->body(), $limit);

        return [
            'source' => 'html',
            'query_url' => 'https://www.bing.com/search?' . http_build_query(['q' => $query, 'count' => $limit, 'first' => $offset + 1]),
            'results' => $this->limitResults($results, $limit),
        ];
    }

    /**
     * @return array{results: array<int, array{title: string, url: string, description: string|null}>, source: string, error?: string}
     */
    private function searchGoogle(string $query, int $limit, int $page): array
    {
        $offset = max(0, ($page - 1) * $limit);
        $start = $offset + 1;
        $apiKey = trim((string) config('services.google.search_api_key', env('GOOGLE_SEARCH_API_KEY', '')));
        $engineId = trim((string) config('services.google.search_engine_id', env('GOOGLE_SEARCH_ENGINE_ID', '')));

        if ($apiKey !== '' && $engineId !== '') {
            return $this->searchGoogleViaApi($query, $limit, $start, $apiKey, $engineId);
        }

        return $this->searchGoogleViaWeb($query, $limit, $offset);
    }

    /**
     * @return array{results: array<int, array{title: string, url: string, description: string|null}>, source: string, error?: string}
     */
    private function searchGoogleViaApi(string $query, int $limit, int $start, string $apiKey, string $engineId): array
    {
        $response = $this->apiHttpClient()
            ->get('https://www.googleapis.com/customsearch/v1', [
                'key' => $apiKey,
                'cx' => $engineId,
                'q' => $query,
                'num' => min(10, $limit),
                'start' => max(1, $start),
            ]);

        if ($response->failed()) {
            return $this->httpErrorResult('google', $response->status(), $response->reason());
        }

        $items = (array) data_get($response->json(), 'items', []);
        $results = array_map(static fn (array $item): array => [
            'title' => (string) ($item['title'] ?? ''),
            'url' => (string) ($item['link'] ?? ''),
            'description' => isset($item['snippet']) ? (string) $item['snippet'] : null,
        ], $items);

        return [
            'source' => 'api',
            'query_url' => 'https://www.googleapis.com/customsearch/v1?' . http_build_query([
                'cx' => $engineId,
                'q' => $query,
                'num' => min(10, $limit),
                'start' => max(1, $start),
            ]),
            'results' => $this->limitResults($results, $limit),
        ];
    }

    /**
     * @return array{results: array<int, array{title: string, url: string, description: string|null}>, source: string, error?: string}
     */
    private function searchGoogleViaWeb(string $query, int $limit, int $offset): array
    {
        $response = $this->webHttpClient()
            ->get('https://www.google.com/search', [
                'q' => $query,
                'num' => $limit,
                'start' => $offset,
                'hl' => 'en',
            ]);

        if ($response->failed()) {
            return $this->httpErrorResult('google', $response->status(), $response->reason());
        }

        $results = $this->parseEngineHtmlResults('google', $response->body(), $limit);

        return [
            'source' => 'html',
            'query_url' => 'https://www.google.com/search?' . http_build_query([
                'q' => $query,
                'num' => $limit,
                'start' => $offset,
                'hl' => 'en',
            ]),
            'results' => $this->limitResults($results, $limit),
        ];
    }

    /**
     * @return array{results: array<int, array{title: string, url: string, description: string|null}>, source: string, error?: string}
     */
    private function searchBrave(string $query, int $limit, int $page): array
    {
        $offset = max(0, ($page - 1) * $limit);
        $apiKey = trim((string) config('services.brave.search_api_key', env('BRAVE_SEARCH_API_KEY', '')));

        if ($apiKey !== '') {
            return $this->searchBraveViaApi($query, $limit, $offset, $apiKey);
        }

        return $this->searchBraveViaWeb($query, $limit, $offset);
    }

    /**
     * @return array{results: array<int, array{title: string, url: string, description: string|null}>, source: string, error?: string}
     */
    private function searchBraveViaApi(string $query, int $limit, int $offset, string $apiKey): array
    {
        $response = $this->apiHttpClient()
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Subscription-Token' => $apiKey,
            ])
            ->get('https://api.search.brave.com/res/v1/web/search', [
                'q' => $query,
                'count' => $limit,
                'offset' => $offset,
            ]);

        if ($response->failed()) {
            return $this->httpErrorResult('brave', $response->status(), $response->reason());
        }

        $items = (array) data_get($response->json(), 'web.results', []);
        $results = array_map(static fn (array $item): array => [
            'title' => (string) ($item['title'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'description' => isset($item['description']) ? (string) $item['description'] : null,
        ], $items);

        return [
            'source' => 'api',
            'query_url' => 'https://api.search.brave.com/res/v1/web/search?' . http_build_query([
                'q' => $query,
                'count' => $limit,
                'offset' => $offset,
            ]),
            'results' => $this->limitResults($results, $limit),
        ];
    }

    /**
     * @return array{results: array<int, array{title: string, url: string, description: string|null}>, source: string, error?: string}
     */
    private function searchBraveViaWeb(string $query, int $limit, int $offset): array
    {
        $response = $this->webHttpClient()
            ->get('https://search.brave.com/search', ['q' => $query, 'offset' => $offset]);

        if ($response->failed()) {
            return $this->httpErrorResult('brave', $response->status(), $response->reason());
        }

        $results = $this->parseEngineHtmlResults('brave', $response->body(), $limit);

        return [
            'source' => 'html',
            'query_url' => 'https://search.brave.com/search?' . http_build_query(['q' => $query, 'offset' => $offset]),
            'results' => $this->limitResults($results, $limit),
        ];
    }

    private function webHttpClient(): PendingRequest
    {
        return Http::connectTimeout(10)
            ->timeout(20)
            ->withHeaders([
                'User-Agent' => WebScraperUserAgent::forBotName($this->context->botName()),
                'Accept' => 'text/html,application/xhtml+xml,application/json,text/plain;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ]);
    }

    private function apiHttpClient(): PendingRequest
    {
        return Http::connectTimeout(10)
            ->timeout(20)
            ->withHeaders([
                'Accept' => 'application/json,text/plain;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ]);
    }

    /**
     * @param array<int, array{title: string, url: string, description: string|null}|null> $results
     * @return array<int, array{title: string, url: string, description: string|null}>
     */
    private function limitResults(array $results, int $limit): array
    {
        $normalized = array_values(array_filter($results, function ($result): bool {
            return is_array($result)
                && ($result['title'] ?? '') !== ''
                && ($result['url'] ?? '') !== '';
        }));

        return array_slice($normalized, 0, $limit);
    }

    private function normalizeGoogleUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/url?')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return isset($query['q']) ? $this->normalizeResultUrl((string) $query['q']) : '';
        }

        return $this->normalizeResultUrl($url);
    }

    /**
     * @return array<int, array{title: string, url: string, description: string|null}>
     */
    private function parseEngineHtmlResults(string $engine, string $html, int $limit): array
    {
        $matches = [];
        $results = [];

        $pattern = match ($engine) {
            'bing' => '/<li[^>]*class="[^"]*b_algo[^"]*"[^>]*>.*?<a[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?(?:<p[^>]*>(.*?)<\/p>)?.*?<\/li>/is',
            'google' => '/<a[^>]*href="([^"]+)"[^>]*>\s*<h3[^>]*>(.*?)<\/h3>\s*<\/a>/is',
            'duckduckgo' => '/<div[^>]*class="[^"]*result[^"]*"[^>]*>.*?<a[^>]*class="[^"]*result__a[^"]*"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?(?:<div[^>]*class="[^"]*result__snippet[^"]*"[^>]*>(.*?)<\/div>)?.*?<\/div>/is',
            'brave' => '/<div[^>]*class="[^"]*snippet[^"]*"[^>]*>.*?<a[^>]*class="[^"]*heading-serpresult[^"]*"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?(?:<div[^>]*class="[^"]*snippet-description[^"]*"[^>]*>(.*?)<\/div>)?.*?<\/div>/is',
            default => null,
        };

        if ($pattern === null) {
            return [];
        }

        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $rawUrl = (string) ($match[1] ?? '');
            $title = $this->cleanHtmlText((string) ($match[2] ?? ''));
            $description = $this->cleanHtmlText((string) ($match[3] ?? ''));

            $url = $engine === 'google'
                ? $this->normalizeGoogleUrl($rawUrl)
                : $this->normalizeResultUrl($rawUrl);

            if ($url === '' || $title === '') {
                continue;
            }

            $results[] = [
                'title' => $title,
                'url' => $url,
                'description' => $description !== '' ? $description : null,
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private function cleanHtmlText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeResultUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (str_contains($url, 'duckduckgo.com/l/?')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (isset($query['uddg'])) {
                $url = (string) $query['uddg'];
            }
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    /**
     * @return array{results: array<int, array{title: string, url: string, description: string|null}>, source: string, error: string}
     */
    private function httpErrorResult(string $engine, int $status, ?string $reason): array
    {
        return [
            'source' => 'none',
            'results' => [],
            'error' => sprintf(
                'Failed to fetch %s results (HTTP %d%s).',
                $engine,
                $status,
                $reason ? ' ' . $reason : '',
            ),
        ];
    }

    /**
     * @param array<int, string> $engines
     * @param array<string, array{results: array<int, array{title: string, url: string, description: string|null}>, source: string, query_url?: string, error?: string}> $resultsByEngine
     */
    private function renderMarkdown(
        string $query,
        int $page,
        int $limit,
        array $engines,
        array $resultsByEngine,
    ): string
    {
        $lines = [
            sprintf('## Search results for "%s"', $query),
            sprintf('Page: %d', $page),
            '',
        ];

        foreach ($engines as $engine) {
            $payload = $resultsByEngine[$engine] ?? ['results' => [], 'source' => 'none'];
            $results = $payload['results'] ?? [];
            $source = $payload['source'] ?? 'none';
            $error = $payload['error'] ?? null;
            $queryUrl = $payload['query_url'] ?? null;

            $engineName = $engine === 'duckduckgo' ? 'DuckDuckGo' : ucfirst($engine);
            $lines[] = sprintf('### %s (%s)', $engineName, $source);

            if (is_string($queryUrl) && $queryUrl !== '') {
                $lines[] = sprintf('- Search URL: %s', $queryUrl);
            }

            if (is_string($error) && $error !== '') {
                $lines[] = sprintf('- _Error_: %s', $error);
                $lines[] = '';

                continue;
            }

            if ($results === []) {
                $lines[] = '- No results found.';
                $lines[] = '';

                continue;
            }

            foreach ($results as $result) {
                $title = $result['title'];
                $url = $result['url'];
                $description = $result['description'] ?? null;

                $lines[] = sprintf('- [%s](%s)', $this->escapeMarkdownText($title), $url);

                if ($description !== null && $description !== '') {
                    $lines[] = sprintf('  - %s', $description);
                }
            }

            $lines[] = '';
        }

        $lines[] = 'To continue searching, run this tool again with:';
        $lines[] = sprintf('- `query`: %s', $query);
        $lines[] = sprintf('- `page`: %d', min(self::MAX_PAGE, $page + 1));
        $lines[] = sprintf('- `per_engine_limit`: %d', $limit);
        $lines[] = sprintf('- `engines`: %s', implode(', ', $engines));
        $lines[] = '';
        $lines[] = 'Ask the model to fetch any specific link for deeper analysis.';

        return trim(implode("\n", $lines));
    }

    private function escapeMarkdownText(string $text): string
    {
        return str_replace(['[', ']'], ['\\[', '\\]'], $text);
    }
}
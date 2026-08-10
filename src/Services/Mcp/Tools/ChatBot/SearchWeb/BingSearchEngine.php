<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb;

/**
 * Bing, via the Web Search API when a subscription key is configured and by
 * scraping bing.com otherwise.
 */
final class BingSearchEngine implements SearchEngine
{
    private const WEB_ENDPOINT = 'https://www.bing.com/search';

    private const DEFAULT_API_ENDPOINT = 'https://api.bing.microsoft.com/v7.0/search';

    public function __construct(
        private SearchHttpClients $http,
        private HtmlResultParser $parser,
    ) {
    }

    public function key(): string
    {
        return 'bing';
    }

    public function search(SearchQuery $query): EngineResults
    {
        $apiKey = trim((string) config('code-talker.services.bing.search_api_key', ''));

        return $apiKey !== ''
            ? $this->searchViaApi($query, $apiKey)
            : $this->searchViaWeb($query);
    }

    private function searchViaApi(SearchQuery $query, string $apiKey): EngineResults
    {
        $endpoint = rtrim((string) config('code-talker.services.bing.endpoint', self::DEFAULT_API_ENDPOINT), '/');

        $response = $this->http->api()
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $apiKey])
            ->get($endpoint, [
                'q' => $query->term,
                'count' => $query->limit,
                'offset' => $query->offset(),
                'mkt' => 'en-US',
                'textDecorations' => 'false',
                'textFormat' => 'Raw',
            ]);

        if ($response->failed()) {
            return EngineResults::failed($this->key(), $response->status(), $response->reason());
        }

        $items = (array) data_get($response->json(), 'webPages.value', []);

        $results = array_map(static fn (array $item): SearchResult => new SearchResult(
            title: (string) ($item['name'] ?? ''),
            url: (string) ($item['url'] ?? ''),
            description: isset($item['snippet']) ? (string) $item['snippet'] : null,
        ), $items);

        return EngineResults::fromApi(
            $endpoint . '?' . http_build_query([
                'q' => $query->term,
                'count' => $query->limit,
                'offset' => $query->offset(),
            ]),
            $results,
            $query->limit,
        );
    }

    private function searchViaWeb(SearchQuery $query): EngineResults
    {
        $parameters = [
            'q' => $query->term,
            'count' => $query->limit,
            'first' => $query->start(),
        ];

        $response = $this->http->web()->get(self::WEB_ENDPOINT, $parameters);

        if ($response->failed()) {
            return EngineResults::failed($this->key(), $response->status(), $response->reason());
        }

        return EngineResults::fromHtml(
            self::WEB_ENDPOINT . '?' . http_build_query($parameters),
            $this->parser->parse($this->key(), $response->body(), $query->limit),
            $query->limit,
        );
    }
}

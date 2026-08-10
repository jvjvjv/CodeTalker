<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb;

/**
 * Brave, via its Search API when a subscription token is configured and by
 * scraping search.brave.com otherwise.
 */
final class BraveSearchEngine implements SearchEngine
{
    private const WEB_ENDPOINT = 'https://search.brave.com/search';

    private const API_ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';

    public function __construct(
        private SearchHttpClients $http,
        private HtmlResultParser $parser,
    ) {
    }

    public function key(): string
    {
        return 'brave';
    }

    public function search(SearchQuery $query): EngineResults
    {
        $apiKey = trim((string) config('code-talker.services.brave.search_api_key', ''));

        return $apiKey !== ''
            ? $this->searchViaApi($query, $apiKey)
            : $this->searchViaWeb($query);
    }

    private function searchViaApi(SearchQuery $query, string $apiKey): EngineResults
    {
        $parameters = [
            'q' => $query->term,
            'count' => $query->limit,
            'offset' => $query->offset(),
        ];

        $response = $this->http->api()
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Subscription-Token' => $apiKey,
            ])
            ->get(self::API_ENDPOINT, $parameters);

        if ($response->failed()) {
            return EngineResults::failed($this->key(), $response->status(), $response->reason());
        }

        $items = (array) data_get($response->json(), 'web.results', []);

        $results = array_map(static fn (array $item): SearchResult => new SearchResult(
            title: (string) ($item['title'] ?? ''),
            url: (string) ($item['url'] ?? ''),
            description: isset($item['description']) ? (string) $item['description'] : null,
        ), $items);

        return EngineResults::fromApi(
            self::API_ENDPOINT . '?' . http_build_query($parameters),
            $results,
            $query->limit,
        );
    }

    private function searchViaWeb(SearchQuery $query): EngineResults
    {
        $parameters = ['q' => $query->term, 'offset' => $query->offset()];

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

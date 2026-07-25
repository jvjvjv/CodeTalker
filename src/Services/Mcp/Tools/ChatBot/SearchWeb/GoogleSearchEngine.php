<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb;

/**
 * Google, via the Programmable Search JSON API when both a key and a search
 * engine id are configured, and by scraping google.com otherwise.
 */
final class GoogleSearchEngine implements SearchEngine
{
    private const WEB_ENDPOINT = 'https://www.google.com/search';

    private const API_ENDPOINT = 'https://www.googleapis.com/customsearch/v1';

    /**
     * The API caps a single page at 10 results regardless of what was asked for.
     */
    private const API_MAX_RESULTS = 10;

    public function __construct(
        private SearchHttpClients $http,
        private HtmlResultParser $parser,
    ) {
    }

    public function key(): string
    {
        return 'google';
    }

    public function search(SearchQuery $query): EngineResults
    {
        $apiKey = trim((string) config('services.google.search_api_key', env('GOOGLE_SEARCH_API_KEY', '')));
        $engineId = trim((string) config('services.google.search_engine_id', env('GOOGLE_SEARCH_ENGINE_ID', '')));

        return $apiKey !== '' && $engineId !== ''
            ? $this->searchViaApi($query, $apiKey, $engineId)
            : $this->searchViaWeb($query);
    }

    private function searchViaApi(SearchQuery $query, string $apiKey, string $engineId): EngineResults
    {
        $parameters = [
            'cx' => $engineId,
            'q' => $query->term,
            'num' => min(self::API_MAX_RESULTS, $query->limit),
            'start' => max(1, $query->start()),
        ];

        $response = $this->http->api()->get(self::API_ENDPOINT, ['key' => $apiKey] + $parameters);

        if ($response->failed()) {
            return EngineResults::failed($this->key(), $response->status(), $response->reason());
        }

        $items = (array) data_get($response->json(), 'items', []);

        $results = array_map(static fn (array $item): SearchResult => new SearchResult(
            title: (string) ($item['title'] ?? ''),
            url: (string) ($item['link'] ?? ''),
            description: isset($item['snippet']) ? (string) $item['snippet'] : null,
        ), $items);

        // The key is deliberately left out of the echoed query url.
        return EngineResults::fromApi(
            self::API_ENDPOINT . '?' . http_build_query($parameters),
            $results,
            $query->limit,
        );
    }

    private function searchViaWeb(SearchQuery $query): EngineResults
    {
        $parameters = [
            'q' => $query->term,
            'num' => $query->limit,
            'start' => $query->offset(),
            'hl' => 'en',
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

<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb;

/**
 * DuckDuckGo, scraped from its no-JS HTML endpoint. It has no keyed API, so
 * this is the only strategy.
 */
final class DuckDuckGoSearchEngine implements SearchEngine
{
    private const ENDPOINT = 'https://duckduckgo.com/html/';

    public function __construct(
        private SearchHttpClients $http,
        private HtmlResultParser $parser,
    ) {
    }

    public function key(): string
    {
        return 'duckduckgo';
    }

    public function search(SearchQuery $query): EngineResults
    {
        $parameters = ['q' => $query->term, 's' => $query->offset()];

        $response = $this->http->web()->get(self::ENDPOINT, $parameters);

        if ($response->failed()) {
            return EngineResults::failed($this->key(), $response->status(), $response->reason());
        }

        return EngineResults::fromHtml(
            self::ENDPOINT . '?' . http_build_query($parameters),
            $this->parser->parse($this->key(), $response->body(), $query->limit),
            $query->limit,
        );
    }
}

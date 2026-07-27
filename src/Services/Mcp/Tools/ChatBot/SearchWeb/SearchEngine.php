<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb;

/**
 * One search engine the `search-web` tool can query.
 *
 * Implementations own their own endpoints and their own choice between an
 * official API (when a key is configured) and scraping the public results page.
 */
interface SearchEngine
{
    /**
     * The engine's key as callers name it, e.g. `duckduckgo`.
     */
    public function key(): string;

    public function search(SearchQuery $query): EngineResults;
}

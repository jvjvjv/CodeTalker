<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb;

use Jvjvjv\CodeTalker\Support\ToolContext;

/**
 * The engines `search-web` can query, and the resolution of caller-supplied
 * engine keys against them.
 *
 * Declaration order here is the order results are returned and rendered in.
 */
final class SearchEngineRegistry
{
    public const SUPPORTED_ENGINES = ['bing', 'google', 'duckduckgo', 'brave'];

    /** @var array<string, SearchEngine>|null */
    private ?array $engines = null;

    public function __construct(
        private ToolContext $context,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function supportedKeys(): array
    {
        return self::SUPPORTED_ENGINES;
    }

    /**
     * Normalize a caller-supplied engine list, falling back to every engine.
     *
     * @param mixed $requested
     * @return array<int, string>
     */
    public function normalizeRequested(mixed $requested): array
    {
        $keys = array_values(array_unique(array_map(
            static fn ($engine): string => strtolower(trim((string) $engine)),
            (array) ($requested ?? self::SUPPORTED_ENGINES),
        )));

        return $keys !== [] ? $keys : self::SUPPORTED_ENGINES;
    }

    /**
     * @param array<int, string> $keys
     * @return array<int, string> the keys this registry cannot serve
     */
    public function unsupported(array $keys): array
    {
        return array_values(array_diff($keys, self::SUPPORTED_ENGINES));
    }

    public function engine(string $key): SearchEngine
    {
        return $this->engines()[$key];
    }

    /**
     * @return array<string, SearchEngine>
     */
    private function engines(): array
    {
        if ($this->engines !== null) {
            return $this->engines;
        }

        $http = new SearchHttpClients($this->context);
        $parser = new HtmlResultParser();

        $engines = [
            new BingSearchEngine($http, $parser),
            new GoogleSearchEngine($http, $parser),
            new DuckDuckGoSearchEngine($http, $parser),
            new BraveSearchEngine($http, $parser),
        ];

        $this->engines = [];

        foreach ($engines as $engine) {
            $this->engines[$engine->key()] = $engine;
        }

        return $this->engines;
    }
}

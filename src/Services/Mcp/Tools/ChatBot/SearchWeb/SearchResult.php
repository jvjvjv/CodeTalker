<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb;

/**
 * One result link returned by a search engine.
 */
final class SearchResult
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $description = null,
    ) {
    }

    /**
     * @return array{title: string, url: string, description: string|null}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'description' => $this->description,
        ];
    }

    /**
     * Drop results missing a title or url, then cap the list.
     *
     * Applied to every engine's output — API responses can carry entries with
     * blank fields, and the HTML parsers can over-match.
     *
     * @param array<int, self> $results
     * @return array<int, self>
     */
    public static function usable(array $results, int $limit): array
    {
        $normalized = array_values(array_filter(
            $results,
            static fn (self $result): bool => $result->title !== '' && $result->url !== '',
        ));

        return array_slice($normalized, 0, $limit);
    }
}

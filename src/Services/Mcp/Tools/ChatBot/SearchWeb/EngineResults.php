<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb;

/**
 * One engine's contribution to a search, in one of three states: it returned
 * results, it answered with an HTTP failure, or the call threw outright.
 *
 * Each state serializes to a slightly different array — a thrown call reports
 * no source at all — and those shapes are part of the tool's response contract,
 * so {@see toArray()} reproduces them exactly.
 */
final class EngineResults
{
    /**
     * @param array<int, SearchResult> $results
     */
    private function __construct(
        public readonly ?string $source,
        public readonly ?string $queryUrl,
        public readonly array $results,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * Results scraped from an engine's public results page.
     *
     * @param array<int, SearchResult> $results
     */
    public static function fromHtml(string $queryUrl, array $results, int $limit): self
    {
        return new self('html', $queryUrl, SearchResult::usable($results, $limit));
    }

    /**
     * Results returned by an engine's official API.
     *
     * @param array<int, SearchResult> $results
     */
    public static function fromApi(string $queryUrl, array $results, int $limit): self
    {
        return new self('api', $queryUrl, SearchResult::usable($results, $limit));
    }

    /**
     * The engine answered, but not successfully.
     */
    public static function failed(string $engine, int $status, ?string $reason): self
    {
        return new self('none', null, [], sprintf(
            'Failed to fetch %s results (HTTP %d%s).',
            $engine,
            $status,
            $reason ? ' ' . $reason : '',
        ));
    }

    /**
     * The call to the engine threw — a transport failure, not an HTTP status.
     */
    public static function threw(string $engine, string $message): self
    {
        return new self(null, null, [], sprintf('Search failed on %s: %s', $engine, $message));
    }

    public function failedOrThrew(): bool
    {
        return $this->error !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->error !== null) {
            // A thrown call reports only the failure; an HTTP failure also
            // names its source.
            return $this->source === null
                ? ['results' => [], 'error' => $this->error]
                : ['source' => $this->source, 'results' => [], 'error' => $this->error];
        }

        return [
            'source' => $this->source,
            'query_url' => $this->queryUrl,
            'results' => array_map(
                static fn (SearchResult $result): array => $result->toArray(),
                $this->results,
            ),
        ];
    }
}

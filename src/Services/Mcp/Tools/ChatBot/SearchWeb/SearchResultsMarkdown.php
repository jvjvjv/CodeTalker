<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb;

/**
 * Renders a search into the markdown document the model actually reads,
 * ending with the inputs needed to page further.
 */
final class SearchResultsMarkdown
{
    /**
     * @param array<int, string> $engines
     * @param array<string, EngineResults> $resultsByEngine
     */
    public function render(SearchQuery $query, int $nextPage, array $engines, array $resultsByEngine): string
    {
        $lines = [
            sprintf('## Search results for "%s"', $query->term),
            sprintf('Page: %d', $query->page),
            '',
        ];

        foreach ($engines as $engine) {
            $results = $resultsByEngine[$engine] ?? null;

            $lines[] = sprintf('### %s (%s)', $this->engineName($engine), $results?->source ?? 'none');

            if ($results?->queryUrl !== null && $results?->queryUrl !== '') {
                $lines[] = sprintf('- Search URL: %s', $results->queryUrl);
            }

            if ($results?->error !== null && $results?->error !== '') {
                $lines[] = sprintf('- _Error_: %s', $results->error);
                $lines[] = '';

                continue;
            }

            if ($results === null || $results->results === []) {
                $lines[] = '- No results found.';
                $lines[] = '';

                continue;
            }

            foreach ($results->results as $result) {
                $lines[] = sprintf('- [%s](%s)', $this->escape($result->title), $result->url);

                if ($result->description !== null && $result->description !== '') {
                    $lines[] = sprintf('  - %s', $result->description);
                }
            }

            $lines[] = '';
        }

        $lines[] = 'To continue searching, run this tool again with:';
        $lines[] = sprintf('- `query`: %s', $query->term);
        $lines[] = sprintf('- `page`: %d', $nextPage);
        $lines[] = sprintf('- `per_engine_limit`: %d', $query->limit);
        $lines[] = sprintf('- `engines`: %s', implode(', ', $engines));
        $lines[] = '';
        $lines[] = 'Ask the model to fetch any specific link for deeper analysis.';

        return trim(implode("\n", $lines));
    }

    private function engineName(string $engine): string
    {
        return $engine === 'duckduckgo' ? 'DuckDuckGo' : ucfirst($engine);
    }

    private function escape(string $text): string
    {
        return str_replace(['[', ']'], ['\\[', '\\]'], $text);
    }
}

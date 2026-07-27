<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb;

/**
 * Scrapes result links out of an engine's public results page.
 *
 * Each engine gets one pattern capturing url, title, and (optionally) snippet.
 * These are best-effort against markup we do not control: an engine that
 * restyles its results page simply stops matching and yields no results, which
 * the caller reports as an empty result set rather than an error.
 */
final class HtmlResultParser
{
    public function __construct(
        private ResultUrlNormalizer $urls = new ResultUrlNormalizer(),
    ) {
    }

    /**
     * @return array<int, SearchResult>
     */
    public function parse(string $engine, string $html, int $limit): array
    {
        $matches = [];
        $results = [];

        $pattern = $this->patternFor($engine);

        if ($pattern === null) {
            return [];
        }

        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $rawUrl = (string) ($match[1] ?? '');
            $title = $this->cleanText((string) ($match[2] ?? ''));
            $description = $this->cleanText((string) ($match[3] ?? ''));

            $url = $engine === 'google'
                ? $this->urls->normalizeGoogle($rawUrl)
                : $this->urls->normalize($rawUrl);

            if ($url === '' || $title === '') {
                continue;
            }

            $results[] = new SearchResult(
                title: $title,
                url: $url,
                description: $description !== '' ? $description : null,
            );

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private function patternFor(string $engine): ?string
    {
        return match ($engine) {
            'bing' => '/<li[^>]*class="[^"]*b_algo[^"]*"[^>]*>.*?<a[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?(?:<p[^>]*>(.*?)<\/p>)?.*?<\/li>/is',
            'google' => '/<a[^>]*href="([^"]+)"[^>]*>\s*<h3[^>]*>(.*?)<\/h3>\s*<\/a>/is',
            'duckduckgo' => '/<div[^>]*class="[^"]*result[^"]*"[^>]*>.*?<a[^>]*class="[^"]*result__a[^"]*"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?(?:<div[^>]*class="[^"]*result__snippet[^"]*"[^>]*>(.*?)<\/div>)?.*?<\/div>/is',
            'brave' => '/<div[^>]*class="[^"]*snippet[^"]*"[^>]*>.*?<a[^>]*class="[^"]*heading-serpresult[^"]*"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?(?:<div[^>]*class="[^"]*snippet-description[^"]*"[^>]*>(.*?)<\/div>)?.*?<\/div>/is',
            default => null,
        };
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}

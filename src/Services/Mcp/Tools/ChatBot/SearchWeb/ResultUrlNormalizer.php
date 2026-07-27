<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb;

/**
 * Unwraps the redirect URLs search engines wrap their results in, and rejects
 * anything that is not plain http(s).
 */
final class ResultUrlNormalizer
{
    /**
     * @return string the usable URL, or '' when it should be discarded
     */
    public function normalize(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (str_contains($url, 'duckduckgo.com/l/?')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (isset($query['uddg'])) {
                $url = (string) $query['uddg'];
            }
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    /**
     * Google links its results through `/url?q=…` when scraped.
     */
    public function normalizeGoogle(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/url?')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return isset($query['q']) ? $this->normalize((string) $query['q']) : '';
        }

        return $this->normalize($url);
    }
}

<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jvjvjv\CodeTalker\Contracts\Mcp\AiToolHandlerContract;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Support\WebScraperUserAgent;
use Symfony\Component\DomCrawler\Crawler;

class FetchWebPageTool implements AiToolHandlerContract
{
    private const MAX_BODY_LENGTH = 150000;

    private const MAX_CONTENT_LENGTH = 20000;

    public function __construct(
        private AiConversation $conversation,
    ) {}

    public function name(): string
    {
        return 'fetch_web_page';
    }

    public function description(): string
    {
        return 'Fetch a web page by URL and return its readable text content using the JayScraper research user agent.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'description' => 'The full http or https URL of the web page to fetch.',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function handle(array $input): array
    {
        $url = trim((string) ($input['url'] ?? ''));

        if ($url === '') {
            return ['error' => 'A URL is required.'];
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return ['error' => 'The URL must be a valid http or https address.'];
        }

        try {
            $response = Http::connectTimeout(10)
                ->timeout(20)
                ->withHeaders([
                    'User-Agent' => WebScraperUserAgent::forConversation($this->conversation),
                    'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                ])
                ->get($url);
        } catch (ConnectionException $e) {
            Log::warning('fetch_web_page could not connect', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => sprintf('Could not connect to %s. The request failed before receiving a response.', $url),
                'url' => $url,
            ];
        }

        if ($response->failed()) {
            Log::warning('fetch_web_page received an error response', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return [
                'error' => sprintf(
                    'Failed to fetch %s. The server responded with HTTP status %d (%s).',
                    $url,
                    $response->status(),
                    $response->reason() ?: 'Unknown',
                ),
                'url' => $url,
                'status' => $response->status(),
            ];
        }

        $contentType = strtolower((string) ($response->header('Content-Type') ?? ''));
        $body = mb_substr($response->body(), 0, self::MAX_BODY_LENGTH);

        if ($body === '') {
            return ['error' => 'The page returned an empty response body.'];
        }

        if (str_contains($contentType, 'text/plain')) {
            $content = $this->normalizeWhitespace($body);

            return [
                'url' => $url,
                'title' => null,
                'content_type' => $contentType,
                'content' => $this->truncateContent($content),
                'truncated' => mb_strlen($content) > self::MAX_CONTENT_LENGTH,
            ];
        }

        if (!$this->isHtmlResponse($contentType)) {
            return ['error' => 'The URL did not return an HTML or plain text page.'];
        }

        $title = null;

        try {
            $crawler = new Crawler($body, $url);
            $title = $crawler->filter('title')->count() > 0
                ? trim($crawler->filter('title')->first()->text(''))
                : null;
        } catch (\Throwable) {
            $title = null;
        }

        $content = $this->extractReadableText($body);

        if ($content === '') {
            return ['error' => 'No readable page content could be extracted.'];
        }

        return [
            'url' => $url,
            'title' => $title !== '' ? $title : null,
            'content_type' => $contentType,
            'content' => $this->truncateContent($content),
            'truncated' => mb_strlen($content) > self::MAX_CONTENT_LENGTH,
        ];
    }

    private function isHtmlResponse(string $contentType): bool
    {
        return str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml+xml');
    }

    private function extractReadableText(string $html): string
    {
        $withoutNonContent = preg_replace([
            '/<script\b[^>]*>.*?<\/script>/is',
            '/<style\b[^>]*>.*?<\/style>/is',
            '/<noscript\b[^>]*>.*?<\/noscript>/is',
            '/<svg\b[^>]*>.*?<\/svg>/is',
        ], '', $html) ?? $html;

        $withBlockBreaks = preg_replace('/<(\/p|\/div|\/section|\/article|\/li|\/h[1-6]|br)\b[^>]*>/i', "$0\n", $withoutNonContent)
            ?? $withoutNonContent;

        $decoded = html_entity_decode(strip_tags($withBlockBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->normalizeWhitespace($decoded);
    }

    private function normalizeWhitespace(string $content): string
    {
        $content = preg_replace("/\r\n?|\f/u", "\n", $content) ?? $content;
        $content = preg_replace('/[^\S\n]+/u', ' ', $content) ?? $content;
        $content = preg_replace('/\n{3,}/u', "\n\n", $content) ?? $content;

        return trim($content);
    }

    private function truncateContent(string $content): string
    {
        return mb_substr($content, 0, self::MAX_CONTENT_LENGTH);
    }
}

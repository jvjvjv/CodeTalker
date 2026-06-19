<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Jvjvjv\CodeTalker\Support\WebScraperUserAgent;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\DomCrawler\Crawler;

#[Name('fetch-web-page')]
#[Description('Fetch a web page by URL and return its readable text content using the JayScraper research user agent.')]
class FetchWebPageTool extends Tool
{
    private const MAX_BODY_LENGTH = 150000;

    private const MAX_CONTENT_LENGTH = 20000;

    public function __construct(
        private ToolContext $context,
    ) {}

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->format('uri')
                ->description('The full http or https URL of the web page to fetch.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $url = trim((string) $request->get('url', ''));

        if ($url === '') {
            return Response::error('A URL is required.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return Response::error('The URL must be a valid http or https address.');
        }

        try {
            $response = Http::connectTimeout(10)
                ->timeout(20)
                ->withHeaders([
                    'User-Agent' => WebScraperUserAgent::forBotName($this->context->botName()),
                    'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                ])
                ->get($url);
        } catch (ConnectionException $e) {
            Log::warning('fetch-web-page could not connect', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return Response::error(sprintf('Could not connect to %s. The request failed before receiving a response.', $url));
        }

        if ($response->failed()) {
            Log::warning('fetch-web-page received an error response', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return Response::error(sprintf(
                'Failed to fetch %s. The server responded with HTTP status %d (%s).',
                $url,
                $response->status(),
                $response->reason() ?: 'Unknown',
            ));
        }

        $contentType = strtolower((string) ($response->header('Content-Type') ?? ''));
        $body = mb_substr($response->body(), 0, self::MAX_BODY_LENGTH);

        if ($body === '') {
            return Response::error('The page returned an empty response body.');
        }

        if (str_contains($contentType, 'text/plain')) {
            $content = $this->normalizeWhitespace($body);

            return Response::structured([
                'url' => $url,
                'title' => null,
                'content_type' => $contentType,
                'content' => $this->truncateContent($content),
                'truncated' => mb_strlen($content) > self::MAX_CONTENT_LENGTH,
            ]);
        }

        if (!$this->isHtmlResponse($contentType)) {
            return Response::error('The URL did not return an HTML or plain text page.');
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
            return Response::error('No readable page content could be extracted.');
        }

        return Response::structured([
            'url' => $url,
            'title' => $title !== '' ? $title : null,
            'content_type' => $contentType,
            'content' => $this->truncateContent($content),
            'truncated' => mb_strlen($content) > self::MAX_CONTENT_LENGTH,
        ]);
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

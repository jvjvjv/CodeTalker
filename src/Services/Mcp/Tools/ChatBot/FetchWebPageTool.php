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
            'keep_html' => $schema->boolean()
                ->description('Indicate whether HTML should be kept or stripped. Only works for HTML responses.'),
            'truncate_content' => $schema->boolean()
                ->description('Indicate whether content should be truncated at ' . self::MAX_CONTENT_LENGTH . ' bytes.'),
            'target_selector' => $schema->string()
                ->description('Selector to target; everything outside of that target_selector will be trimmed. Only works for HTML responses.'),
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
                    'Accept' => 'text/html;q=1.0,application/xhtml+xml;q=0.9,application/xml;q=0.8,text/plain;q=0.5,*/*;q=0.7',
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

        $truncateContent = (bool) $request->get('truncate_content', true);

        if (str_contains($contentType, 'text/plain')) {
            $content = $this->normalizeWhitespace($body);

            return Response::structured([
                'url' => $url,
                'title' => null,
                'content_type' => $contentType,
                'content' => $truncateContent ? $this->truncateContent($content) : $content,
                'truncated' => $truncateContent && mb_strlen($content) > self::MAX_CONTENT_LENGTH,
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

        $targetSelector = trim((string) $request->get('target_selector', ''));

        if ($targetSelector !== '') {
            $targetHtml = $this->extractTargetHtml($body, $url, $targetSelector);

            if ($targetHtml === null) {
                return Response::error(sprintf('No elements matched target_selector "%s".', $targetSelector));
            }

            $body = $targetHtml;
        }

        $keepHtml = $request->get('keep_html', false);
        $content = $keepHtml ? $body : $this->extractReadableText($body);

        if ($content === '') {
            return Response::error('No readable page content could be extracted.');
        }

        return Response::structured([
            'url' => $url,
            'title' => $title !== '' ? $title : null,
            'content_type' => $contentType,
            'content' => $truncateContent ? $this->truncateContent($content) : $content,
            'truncated' => $truncateContent && mb_strlen($content) > self::MAX_CONTENT_LENGTH,
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
            '/<head\b[^>]*>.*?<\/head>/is',
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

    private function extractTargetHtml(string $html, string $url, string $selector): ?string {
        try {
            $crawler = new Crawler($html, $url);
            $target = $crawler->filter($selector);

            if ($target->count() === 0) {
                return null;
            }

            $node = $target->getNode(0);

            if ($node === null || $node->ownerDocument === null) {
                return null;
            }

            $outerHtml = $node->ownerDocument->saveHTML($node);

            return is_string($outerHtml) ? trim($outerHtml) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

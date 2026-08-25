<?php

namespace Jvjvjv\CodeTalker\Services\Web;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jvjvjv\CodeTalker\Support\WebScraperUserAgent;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The package's outbound HTTP capability, shared by the `fetch-web-page` and
 * `http-request` chat-bot tools.
 *
 * This class deliberately lives outside `Services/Mcp/Tools/`: those directories
 * are walked recursively by {@see \Jvjvjv\CodeTalker\Services\Mcp\DiscoversAiToolHandlers},
 * and anything there that extends a Tool base class registers itself as a tool.
 * Shared infrastructure kept out of that tree cannot become a phantom tool.
 *
 * `fetchPage()` reproduces `fetch-web-page` exactly — same headers, same
 * timeouts, same caps, same error strings. `request()` adds arbitrary methods,
 * request bodies, header filtering, and JSON/XML decoding.
 */
class WebFetcher
{
    /** Bytes read off the wire before the body is cut. */
    public const MAX_BODY_LENGTH = 150000;

    /** Characters of decoded content returned unless truncation is declined. */
    public const MAX_CONTENT_LENGTH = 20000;

    private const CONNECT_TIMEOUT = 10;

    private const TIMEOUT = 20;

    /** Redirect hops followed before a request is abandoned; matches Guzzle's own default. */
    private const MAX_REDIRECTS = 5;

    /**
     * Request headers a caller may never set.
     *
     * Authentication headers are excluded because credentials come from host
     * configuration, never from the model. Hop-by-hop headers are excluded
     * because they describe a connection the caller does not own.
     */
    private const FORBIDDEN_REQUEST_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'host',
        'connection',
        'keep-alive',
        'transfer-encoding',
        'te',
        'trailer',
        'upgrade',
        'proxy-connection',
    ];

    /** Headers the package owns; a caller-supplied value of the same name loses. */
    private const PACKAGE_OWNED_HEADERS = [
        'user-agent',
        'accept-encoding',
    ];

    public function __construct(
        private readonly ?string $botName = null,
        private readonly string $logLabel = 'fetch-web-page',
    ) {}

    /**
     * Fetch a readable web page — the `fetch-web-page` behavior.
     *
     * GET only, and only HTML, XHTML, or plain text. Every error string here is
     * pinned by FetchWebPageToolTest.
     */
    public function fetchPage(
        string $url,
        bool $keepHtml = false,
        string $targetSelector = '',
        bool $truncate = true,
    ): FetchedResponse {
        if (($invalid = $this->validateUrl($url)) !== null) {
            return $invalid;
        }

        $sent = $this->send('GET', $url, null, []);

        if ($sent instanceof FetchedResponse) {
            return $sent;
        }

        [$status, $contentType, $body] = $sent;

        if ($body === '') {
            return FetchedResponse::failure($url, 'The page returned an empty response body.');
        }

        if (str_contains($contentType, 'text/plain')) {
            return $this->textResponse($url, $status, $contentType, $body, $truncate);
        }

        if (!$this->isHtml($contentType)) {
            return FetchedResponse::failure($url, 'The URL did not return an HTML or plain text page.');
        }

        return $this->htmlResponse($url, $status, $contentType, $body, $keepHtml, $targetSelector, $truncate);
    }

    /**
     * Issue an arbitrary HTTP request and decode the response — the
     * `http-request` behavior.
     *
     * The caller's headers are filtered before the request; host-configured
     * credentials are applied after, so a credential can set a header the
     * caller is forbidden to set.
     *
     * Redirects are NOT followed automatically here. Guzzle's default is to
     * follow up to five hops, which would let a public URL bounce the request
     * into a private network behind the caller's back — the gate would have
     * validated only the first hop. Each hop is instead re-validated through
     * $validateHop before it is issued.
     *
     * @param array<string, mixed> $headers
     * @param (callable(string, string): ?string)|null $validateHop Given a URL and method, returns a refusal message or null
     */
    public function request(
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
        bool $keepHtml = false,
        string $targetSelector = '',
        bool $truncate = true,
        ?callable $validateHop = null,
    ): FetchedResponse {
        if (($invalid = $this->validateUrl($url)) !== null) {
            return $invalid;
        }

        [$safeHeaders, $strippedHeaders] = $this->filterRequestHeaders($headers);

        $sent = $this->sendFollowingRedirects(strtoupper($method), $url, $body, $safeHeaders, $validateHop);

        if ($sent instanceof FetchedResponse) {
            return $sent->withStrippedHeaders($strippedHeaders);
        }

        [$status, $contentType, $responseBody, , $finalUrl] = $sent;

        return $this
            ->decode($finalUrl, $status, $contentType, $responseBody, $keepHtml, $targetSelector, $truncate)
            ->withStrippedHeaders($strippedHeaders);
    }

    /**
     * Issue a request, re-validating and re-issuing on each redirect.
     *
     * Credentials are re-derived per hop from the hop's own host, so a redirect
     * to a different host cannot carry the first host's token with it.
     *
     * @param array<string, string> $safeHeaders
     * @param (callable(string, string): ?string)|null $validateHop
     * @return array{0: int, 1: string, 2: string, 3: ?string, 4: string}|FetchedResponse
     */
    private function sendFollowingRedirects(
        string $method,
        string $url,
        ?string $body,
        array $safeHeaders,
        ?callable $validateHop,
    ): array|FetchedResponse {
        $currentUrl = $url;
        $currentMethod = $method;
        $currentBody = $body;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if ($hop > 0 && $validateHop !== null) {
                $refusal = $validateHop($currentUrl, $currentMethod);

                if ($refusal !== null) {
                    return FetchedResponse::failure($currentUrl, sprintf(
                        'The request was redirected to %s, and that destination was refused. %s',
                        $currentUrl,
                        $refusal,
                    ));
                }
            }

            $sent = $this->send(
                $currentMethod,
                $currentUrl,
                $currentBody,
                array_merge($safeHeaders, $this->credentialsFor($currentUrl)),
                followRedirects: false,
            );

            if ($sent instanceof FetchedResponse) {
                return $sent;
            }

            [$status, $contentType, $responseBody, $location] = $sent;

            if ($location === null || !$this->isRedirect($status)) {
                return [$status, $contentType, $responseBody, $location, $currentUrl];
            }

            $currentUrl = $this->resolveLocation($currentUrl, $location);

            if ($currentUrl === null) {
                return FetchedResponse::failure(
                    $url,
                    sprintf('The server redirected to a location that could not be resolved: "%s".', $location),
                    $status,
                );
            }

            // 303 always becomes a GET, and 301/302 do in practice; 307/308 preserve
            // the method and body. This mirrors Guzzle's non-strict behavior.
            if (in_array($status, [301, 302, 303], true)) {
                $currentMethod = 'GET';
                $currentBody = null;
            }
        }

        return FetchedResponse::failure($url, sprintf(
            'The request exceeded %d redirects without reaching a final response.',
            self::MAX_REDIRECTS,
        ));
    }

    private function isRedirect(int $status): bool
    {
        return in_array($status, [301, 302, 303, 307, 308], true);
    }

    /**
     * Resolve a Location header, which may be relative, against the URL it came from.
     */
    private function resolveLocation(string $base, string $location): ?string
    {
        if (trim($location) === '') {
            return null;
        }

        try {
            return (string) UriResolver::resolve(new Uri($base), new Uri(trim($location)));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Refuse anything that is not an absolute http or https URL.
     *
     * This is not negotiable by a caller-supplied policy: no legitimate request
     * policy wants `file://`.
     */
    private function validateUrl(string $url): ?FetchedResponse
    {
        if (trim($url) === '') {
            return FetchedResponse::failure($url, 'A URL is required.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return FetchedResponse::failure($url, 'The URL must be a valid http or https address.');
        }

        return null;
    }

    /**
     * Perform one request.
     *
     * $followRedirects is left on for `fetch-web-page`, whose behavior is
     * unchanged by contract, and turned off for `http-request`, where each hop
     * is validated by {@see sendFollowingRedirects()} before it is issued.
     *
     * @param array<string, mixed> $headers
     * @return array{0: int, 1: string, 2: string, 3: ?string}|FetchedResponse Tuple on success, failure otherwise
     */
    private function send(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        bool $followRedirects = true,
    ): array|FetchedResponse {
        $request = Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->withHeaders($this->mergeHeaders($this->defaultHeaders(), $headers));

        if (!$followRedirects) {
            $request = $request->withOptions(['allow_redirects' => false]);
        }

        if ($body !== null && $body !== '') {
            $request = $request->withBody($body, $this->requestContentType($headers));
        }

        try {
            $response = $request->send($method, $url);
        } catch (ConnectionException $e) {
            Log::warning($this->logLabel . ' could not connect', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return FetchedResponse::failure(
                $url,
                sprintf('Could not connect to %s. The request failed before receiving a response.', $url),
            );
        }

        if ($response->failed()) {
            Log::warning($this->logLabel . ' received an error response', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return FetchedResponse::failure(
                $url,
                sprintf(
                    'Failed to fetch %s. The server responded with HTTP status %d (%s).',
                    $url,
                    $response->status(),
                    $response->reason() ?: 'Unknown',
                ),
                $response->status(),
            );
        }

        $location = $response->header('Location');

        return [
            $response->status(),
            strtolower((string) ($response->header('Content-Type') ?? '')),
            mb_substr($response->body(), 0, self::MAX_BODY_LENGTH),
            ($location === null || $location === '') ? null : $location,
        ];
    }

    /**
     * The browser-like header set every outbound request carries.
     *
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        return [
            'User-Agent' => WebScraperUserAgent::forBotName($this->botName),
            'Accept' => 'text/html;q=1.0,application/xhtml+xml;q=0.9,application/xml;q=0.8,text/plain;q=0.5,*/*;q=0.7',
            'Accept-Language' => 'en-US,en;q=0.5',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Upgrade-Insecure-Requests' => '1',
        ];
    }

    /**
     * Overlay one header set on another, matching names case-insensitively so
     * a caller's `accept` replaces the default `Accept` instead of joining it.
     *
     * @param array<string, string> $base
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function mergeHeaders(array $base, array $overrides): array
    {
        $merged = $base;

        foreach ($overrides as $name => $value) {
            $normalized = strtolower(trim((string) $name));

            foreach (array_keys($merged) as $existing) {
                if (strtolower(trim((string) $existing)) === $normalized) {
                    unset($merged[$existing]);
                }
            }

            $merged[(string) $name] = (string) $value;
        }

        return $merged;
    }

    /**
     * Strip headers a caller may not set, and headers the package owns.
     *
     * @param array<string, mixed> $headers
     * @return array{0: array<string, string>, 1: array<int, string>} Kept headers, refused header names
     */
    private function filterRequestHeaders(array $headers): array
    {
        $kept = [];
        $stripped = [];

        foreach ($headers as $name => $value) {
            $normalized = strtolower(trim((string) $name));

            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, self::FORBIDDEN_REQUEST_HEADERS, true)
                || in_array($normalized, self::PACKAGE_OWNED_HEADERS, true)) {
                $stripped[] = (string) $name;

                continue;
            }

            $kept[(string) $name] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }

        return [$kept, $stripped];
    }

    /**
     * Credential headers configured by the host for this URL's exact host.
     *
     * Read with an inline default: Laravel skips mergeConfigFrom entirely when
     * the host has cached config, so a host that published code-talker.php
     * before this key existed would otherwise resolve null in production only.
     *
     * @return array<string, string>
     */
    private function credentialsFor(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return [];
        }

        /** @var array<string, array<string, string>> $configured */
        $configured = (array) config('code-talker.tools.http_request.credentials', []);

        foreach ($configured as $configuredHost => $headers) {
            if (strtolower(trim((string) $configuredHost)) === $host && is_array($headers)) {
                return array_map('strval', $headers);
            }
        }

        return [];
    }

    /**
     * The Content-Type to send a request body under.
     *
     * @param array<string, mixed> $headers
     */
    private function requestContentType(array $headers): string
    {
        foreach ($headers as $name => $value) {
            if (strtolower(trim((string) $name)) === 'content-type') {
                return (string) $value;
            }
        }

        return 'application/json';
    }

    /**
     * Decode a response body according to its content type.
     *
     * HTML is tested before XML because `application/xhtml+xml` matches both.
     */
    private function decode(
        string $url,
        int $status,
        string $contentType,
        string $body,
        bool $keepHtml,
        string $targetSelector,
        bool $truncate,
    ): FetchedResponse {
        if ($body === '') {
            return FetchedResponse::failure($url, 'The page returned an empty response body.', $status);
        }

        if ($this->isHtml($contentType)) {
            return $this->htmlResponse($url, $status, $contentType, $body, $keepHtml, $targetSelector, $truncate);
        }

        if ($this->isJson($contentType)) {
            return $this->jsonResponse($url, $status, $contentType, $body, $truncate);
        }

        if ($this->isXml($contentType)) {
            return $this->xmlResponse($url, $status, $contentType, $body, $truncate);
        }

        if ($this->isText($contentType)) {
            return $this->textResponse($url, $status, $contentType, $body, $truncate);
        }

        return FetchedResponse::failure(
            $url,
            sprintf(
                'The response content type "%s" is not readable as text. Only JSON, XML, HTML, and text responses are supported.',
                $contentType !== '' ? $contentType : 'unknown',
            ),
            $status,
        );
    }

    private function jsonResponse(string $url, int $status, string $contentType, string $body, bool $truncate): FetchedResponse
    {
        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this
                ->textResponse($url, $status, $contentType, $body, $truncate)
                ->withNotes([
                    'The response declared JSON but could not be parsed (' . json_last_error_msg() . '). The raw body is returned as text.',
                ]);
        }

        return $this->structuredResponse($url, $status, $contentType, $decoded, $truncate);
    }

    private function xmlResponse(string $url, int $status, string $contentType, string $body, bool $truncate): FetchedResponse
    {
        $previousErrorHandling = libxml_use_internal_errors(true);

        // LIBXML_NOENT is deliberately NOT passed: it substitutes entities, which
        // is the XXE vector. LIBXML_NONET blocks network fetches during parsing.
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorHandling);

        if ($xml === false) {
            return $this
                ->textResponse($url, $status, $contentType, $body, $truncate)
                ->withNotes(['The response declared XML but could not be parsed. The raw body is returned as text.']);
        }

        $decoded = json_decode((string) json_encode($xml), true);

        return $this->structuredResponse($url, $status, $contentType, $decoded, $truncate);
    }

    /**
     * Return a decoded structure, falling back to truncated text when it is too
     * large to return whole.
     *
     * Cutting an encoded structure mid-string yields something no parser can
     * read, so an oversized payload is downgraded to text and flagged rather
     * than returned as a broken structure.
     */
    private function structuredResponse(string $url, int $status, string $contentType, mixed $decoded, bool $truncate): FetchedResponse
    {
        $encoded = (string) json_encode($decoded);

        if (!$truncate || mb_strlen($encoded) <= self::MAX_CONTENT_LENGTH) {
            return FetchedResponse::decoded($url, $status, $contentType, $decoded);
        }

        return FetchedResponse::decoded(
            url: $url,
            status: $status,
            contentType: $contentType,
            content: mb_substr($encoded, 0, self::MAX_CONTENT_LENGTH),
            truncated: true,
            notes: [
                'The decoded structure exceeded the content limit, so it is returned as truncated text rather than as an incomplete structure. Request a narrower resource, or set truncate_content to false.',
            ],
        );
    }

    private function textResponse(string $url, int $status, string $contentType, string $body, bool $truncate): FetchedResponse
    {
        $content = $this->normalizeWhitespace($body);

        return FetchedResponse::decoded(
            url: $url,
            status: $status,
            contentType: $contentType,
            content: $truncate ? $this->truncateContent($content) : $content,
            truncated: $truncate && mb_strlen($content) > self::MAX_CONTENT_LENGTH,
        );
    }

    private function htmlResponse(
        string $url,
        int $status,
        string $contentType,
        string $body,
        bool $keepHtml,
        string $targetSelector,
        bool $truncate,
    ): FetchedResponse {
        $title = null;

        try {
            $crawler = new Crawler($body, $url);
            $title = $crawler->filter('title')->count() > 0
                ? trim($crawler->filter('title')->first()->text(''))
                : null;
        } catch (\Throwable) {
            $title = null;
        }

        if (trim($targetSelector) !== '') {
            $targetHtml = $this->extractTargetHtml($body, $url, trim($targetSelector));

            if ($targetHtml === null) {
                return FetchedResponse::failure(
                    $url,
                    sprintf('No elements matched target_selector "%s".', trim($targetSelector)),
                    $status,
                );
            }

            $body = $targetHtml;
        }

        $content = $keepHtml ? $body : $this->extractReadableText($body);

        if ($content === '') {
            return FetchedResponse::failure($url, 'No readable page content could be extracted.', $status);
        }

        return FetchedResponse::decoded(
            url: $url,
            status: $status,
            contentType: $contentType,
            content: $truncate ? $this->truncateContent($content) : $content,
            truncated: $truncate && mb_strlen($content) > self::MAX_CONTENT_LENGTH,
            title: $title !== '' ? $title : null,
        );
    }

    private function isHtml(string $contentType): bool
    {
        return str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml+xml');
    }

    private function isJson(string $contentType): bool
    {
        return str_contains($contentType, 'application/json')
            || (bool) preg_match('#\+json\b#', $contentType);
    }

    private function isXml(string $contentType): bool
    {
        return str_contains($contentType, 'application/xml')
            || str_contains($contentType, 'text/xml')
            || (bool) preg_match('#\+xml\b#', $contentType);
    }

    private function isText(string $contentType): bool
    {
        return str_starts_with($contentType, 'text/');
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

    private function extractTargetHtml(string $html, string $url, string $selector): ?string
    {
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

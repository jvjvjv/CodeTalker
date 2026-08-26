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
    private const CONNECT_TIMEOUT = 10;

    private const TIMEOUT = 20;

    /** Redirect hops followed before a request is abandoned; matches Guzzle's own default. */
    private const MAX_REDIRECTS = 5;

    /**
     * Credential headers a caller may set only when {@see allowsCredentialHeaders()}
     * permits it for the request being made — never unconditionally, since a
     * caller acting on injected instructions could otherwise send a real
     * credential to a host the operator never approved.
     */
    private const CREDENTIAL_HEADERS = [
        'authorization',
        'cookie',
    ];

    /**
     * Request headers a caller may never set, regardless of policy.
     *
     * These describe a connection or a hop the caller does not own — a proxy
     * credential, the Host header, connection-management headers — not the
     * destination's own authentication, so there is no legitimate case for a
     * caller to control them.
     */
    private const FORBIDDEN_REQUEST_HEADERS = [
        'proxy-authorization',
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
        private readonly HostGate $gate,
        private readonly ?string $botName = null,
        private readonly string $logLabel = 'fetch-web-page',
        private readonly WebToolPolicy $policy = new WebToolPolicy(),
    ) {}

    /**
     * Bytes read off the wire before the body is cut. Read with an inline
     * default per the same mergeConfigFrom caveat as {@see credentialsFor()}.
     */
    public static function maxBodyLength(): int
    {
        return (int) config('code-talker.tools.web_fetcher.max_body_length', 150000);
    }

    /** Characters of decoded content returned unless truncation is declined. */
    public static function maxContentLength(): int
    {
        return (int) config('code-talker.tools.web_fetcher.max_content_length', 20000);
    }

    /**
     * Fetch a readable web page — the `fetch-web-page` behavior.
     *
     * GET only, and only HTML, XHTML, or plain text. Every error string here is
     * pinned by FetchWebPageToolTest.
     */
    public function fetchPage(
        string $url,
        RequestPolicy $policy,
        bool $keepHtml = false,
        string $targetSelector = '',
        bool $truncate = true,
    ): FetchedResponse {
        $sent = $this->sendFollowingRedirects('GET', $url, null, [], $policy);

        if ($sent instanceof FetchedResponse) {
            return $sent;
        }

        [$status, $contentType, $body, , $finalUrl] = $sent;

        if ($body === '') {
            return FetchedResponse::failure($finalUrl, 'The page returned an empty response body.');
        }

        if (str_contains($contentType, 'text/plain')) {
            return $this->textResponse($finalUrl, $status, $contentType, $body, $truncate);
        }

        if (!$this->isHtml($contentType)) {
            return FetchedResponse::failure($finalUrl, 'The URL did not return an HTML or plain text page.');
        }

        return $this->htmlResponse($finalUrl, $status, $contentType, $body, $keepHtml, $targetSelector, $truncate);
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
        RequestPolicy $policy,
        ?string $body = null,
        array $headers = [],
        bool $keepHtml = false,
        string $targetSelector = '',
        bool $truncate = true,
    ): FetchedResponse {
        [$safeHeaders, $credentialHeaders, $strippedHeaders] = $this->filterRequestHeaders($headers);

        if (! $this->allowsCredentialHeaders($policy)) {
            array_push($strippedHeaders, ...array_keys($credentialHeaders));
            $credentialHeaders = [];
        }

        $sent = $this->sendFollowingRedirects(
            strtoupper($method),
            $url,
            $body,
            $safeHeaders,
            $policy,
            $credentialHeaders,
            strtolower((string) parse_url($url, PHP_URL_HOST)),
        );

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
     * to a different host cannot carry the first host's token with it. This
     * applies equally to `$credentialHeaders` — the caller's own Authorization/
     * Cookie, permitted by {@see allowsCredentialHeaders()} — which is sent
     * only while the current hop's host still matches `$originalHost`: the
     * model attached that header to the host it named, not to wherever a
     * redirect happens to end up, even one within the same AiSystem's
     * allow-list.
     *
     * @param array<string, string> $safeHeaders
     * @param array<string, string> $credentialHeaders
     * @return array{0: int, 1: string, 2: string, 3: ?string, 4: string}|FetchedResponse
     */
    private function sendFollowingRedirects(
        string $method,
        string $url,
        ?string $body,
        array $safeHeaders,
        RequestPolicy $policy,
        array $credentialHeaders = [],
        string $originalHost = '',
    ): array|FetchedResponse {
        $currentUrl = $url;
        $currentMethod = $method;
        $currentBody = $body;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $refusal = $this->gate->refuse($currentUrl, $currentMethod, $policy, $this->policy->allowedDomains);

            if ($refusal !== null) {
                return FetchedResponse::failure(
                    $currentUrl,
                    $hop === 0 ? $refusal : sprintf(
                        'The request was redirected to %s, and that destination was refused. %s',
                        $currentUrl,
                        $refusal,
                    ),
                );
            }

            $currentHost = strtolower((string) parse_url($currentUrl, PHP_URL_HOST));
            $headersForHop = $currentHost === $originalHost
                ? $this->mergeHeaders($safeHeaders, $credentialHeaders)
                : $safeHeaders;

            $sent = $this->send(
                $currentMethod,
                $currentUrl,
                $currentBody,
                // $headersForHop wins on a name collision: a credential header
                // the caller was explicitly permitted to set is more specific
                // than a host-configured default for the same header name.
                $this->mergeHeaders($this->credentialsFor($currentUrl), $headersForHop),
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
     * Perform one request.
     *
     * Redirects are never followed here. {@see sendFollowingRedirects()} owns
     * the hop loop so every destination passes the gate before it is requested.
     *
     * The address the gate validated is pinned into the connection, so the host
     * cannot resolve to somewhere else between the check and the socket.
     *
     * @param array<string, mixed> $headers
     * @return array{0: int, 1: string, 2: string, 3: ?string}|FetchedResponse Tuple on success, failure otherwise
     */
    private function send(
        string $method,
        string $url,
        ?string $body,
        array $headers,
    ): array|FetchedResponse {
        $request = Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->withHeaders($this->mergeHeaders($this->defaultHeaders(), $headers))
            ->withOptions(array_merge(
                ['allow_redirects' => false],
                $this->addressPinFor($url),
            ));

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
            mb_substr($response->body(), 0, self::maxBodyLength()),
            ($location === null || $location === '') ? null : $location,
        ];
    }

    /**
     * cURL options pinning this URL's host to the address the gate validated.
     *
     * CURLOPT_RESOLVE pre-seeds cURL's DNS cache, so no second lookup happens
     * at connect time. Without this the gate and the socket resolve
     * independently, and a host that answers differently between the two walks
     * straight through the check.
     *
     * Guzzle's CurlFactory passes $options['curl'] through as raw cURL options
     * and Laravel's withOptions() forwards them, so this needs no new
     * dependency. Under a non-cURL handler the option is ignored and behavior
     * falls back to resolving at connect — the gate still runs.
     *
     * @return array<string, mixed>
     */
    private function addressPinFor(string $url): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $address = $this->gate->validatedAddressFor($url);

        if ($host === '' || $address === null) {
            return [];
        }

        // A literal host needs no pinning, and pinning an IPv6 literal to itself
        // confuses curl's cache key.
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);

        // CURLOPT_RESOLVE wants host:port:address, with IPv6 bracketed.
        $entry = sprintf(
            '%s:%d:%s',
            trim($host, '[]'),
            $port,
            str_contains($address, ':') ? '[' . trim($address, '[]') . ']' : $address,
        );

        return ['curl' => [CURLOPT_RESOLVE => [$entry]]];
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
     * Credential headers are pulled into their own bucket rather than kept or
     * stripped here — whether they survive at all (policy) and which hops
     * they travel with (same-host-only) are both decided by the caller, in
     * {@see request()} and {@see sendFollowingRedirects()}.
     *
     * @param array<string, mixed> $headers
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<int, string>} Kept headers, credential headers, stripped header names
     */
    private function filterRequestHeaders(array $headers): array
    {
        $kept = [];
        $credential = [];
        $stripped = [];

        foreach ($headers as $name => $value) {
            $normalized = strtolower(trim((string) $name));

            if ($normalized === '') {
                continue;
            }

            $value = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;

            if (in_array($normalized, self::CREDENTIAL_HEADERS, true)) {
                $credential[(string) $name] = $value;

                continue;
            }

            if (in_array($normalized, self::FORBIDDEN_REQUEST_HEADERS, true)
                || in_array($normalized, self::PACKAGE_OWNED_HEADERS, true)) {
                $stripped[] = (string) $name;

                continue;
            }

            $kept[(string) $name] = $value;
        }

        return [$kept, $credential, $stripped];
    }

    /**
     * Whether this request may carry a caller-supplied Authorization/Cookie
     * header through to the destination.
     *
     * Both conditions are required. `$policy->allowCredentialHeaders` is the
     * caller's own declaration and is not trusted alone — a caller acting on
     * injected instructions could declare it freely. `$this->policy->allowedDomains`
     * is the operator's own boundary — an AiSystem's `web_tool_policy` for the
     * local chat loop, or the global `code-talker.tools.web_fetcher.allowed_domains`
     * config for a caller with no AiSystem at all (see
     * {@see \Jvjvjv\CodeTalker\Support\ToolContext::webToolPolicy()}) — and
     * every hop of this request (including redirects) is already refused by
     * HostGate unless it stays within that list. So once both hold, there is
     * no host this request can reach that the operator did not approve.
     */
    private function allowsCredentialHeaders(RequestPolicy $policy): bool
    {
        return $policy->allowCredentialHeaders
            && $this->policy->allowedDomains !== null
            && $this->policy->allowedDomains !== [];
    }

    /**
     * Credential headers for this URL's exact host: the AiSystem's own
     * `web_tool_policy` takes precedence, falling back to the global
     * host-keyed config map.
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

        $scoped = $this->policy->credentialsFor($host);

        if ($scoped !== []) {
            return $scoped;
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

        if (!$truncate || mb_strlen($encoded) <= self::maxContentLength()) {
            return FetchedResponse::decoded($url, $status, $contentType, $decoded);
        }

        return FetchedResponse::decoded(
            url: $url,
            status: $status,
            contentType: $contentType,
            content: mb_substr($encoded, 0, self::maxContentLength()),
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
            truncated: $truncate && mb_strlen($content) > self::maxContentLength(),
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
            truncated: $truncate && mb_strlen($content) > self::maxContentLength(),
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
        return mb_substr($content, 0, self::maxContentLength());
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

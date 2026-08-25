<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Jvjvjv\CodeTalker\Services\Web\WebFetcher;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('http-request')]
#[Description(
    'Make an HTTP request and read the decoded response — JSON, XML, plain text, or HTML. '
    . 'Use this for APIs and non-HTML resources; use fetch-web-page for reading an ordinary web page. '
    . 'You MUST supply request_policy declaring the methods you intend to use, and whether you intend '
    . 'to reach private or loopback hosts. A request with no declared policy is refused before it is sent. '
    . 'Do not send credentials: authentication headers are stripped, and the host supplies any that are needed.'
)]
class HttpRequestTool extends Tool
{
    private const SUPPORTED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

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
                ->description('The full http or https URL to request.')
                ->required(),
            'method' => $schema->string()
                ->enum(self::SUPPORTED_METHODS)
                ->description('The HTTP method. Must also appear in request_policy.allowed_methods.')
                ->required(),
            'request_policy' => $schema->object([
                'allowed_methods' => $schema->array()
                    ->items($schema->string()->enum(self::SUPPORTED_METHODS))
                    ->min(1)
                    ->description('The methods you intend to use in this request. Declare only what you need.')
                    ->required(),
                'allow_private_hosts' => $schema->boolean()
                    ->description(
                        'Set true only when you deliberately intend to reach a loopback, link-local, or private '
                        . 'network address. Defaults to false, which refuses such hosts.'
                    ),
                'allowed_hosts' => $schema->array()
                    ->items($schema->string())
                    ->description('Optional. When given, the request is refused unless the URL host is in this list.'),
            ])
                ->description(
                    'Required. Your declared intent for this request. The request is refused when this is missing, '
                    . 'and refused again if the request falls outside what you declared here.'
                )
                ->required(),
            'body' => $schema->string()
                ->description('Optional request body, sent as-is. Set a Content-Type header to describe it.'),
            'headers' => $schema->object()
                ->description(
                    'Optional request headers. Authentication headers (Authorization, Cookie) and connection '
                    . 'headers are stripped and reported back; the host supplies credentials from its own config.'
                ),
            'keep_html' => $schema->boolean()
                ->description('Indicate whether HTML should be kept or stripped. Only works for HTML responses.'),
            'truncate_content' => $schema->boolean()
                ->description('Indicate whether content should be truncated at ' . WebFetcher::MAX_CONTENT_LENGTH . ' bytes.'),
            'target_selector' => $schema->string()
                ->description('Selector to target; everything outside of that target_selector will be trimmed. Only works for HTML responses.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $url = trim((string) $request->get('url', ''));
        $method = strtoupper(trim((string) $request->get('method', '')));

        $policy = (array) ($request->get('request_policy') ?? []);

        if (($refusal = $this->refuseUnsupportedScheme($url)) !== null) {
            return Response::error($refusal);
        }

        if (($refusal = $this->refuseOutsidePolicy($url, $method, $policy)) !== null) {
            return Response::error($refusal);
        }

        $fetched = (new WebFetcher($this->context->botName(), 'http-request'))->request(
            method: $method,
            url: $url,
            body: $this->bodyFrom($request),
            headers: (array) ($request->get('headers') ?? []),
            keepHtml: (bool) $request->get('keep_html', false),
            targetSelector: trim((string) $request->get('target_selector', '')),
            truncate: (bool) $request->get('truncate_content', true),
            // A redirect is a new request to a new host. Validating only the URL
            // the model named would let a public host bounce this into a private
            // network, defeating the gate entirely.
            validateHop: fn (string $hopUrl, string $hopMethod): ?string
                => $this->refuseUnsupportedScheme($hopUrl) ?? $this->refuseOutsidePolicy($hopUrl, $hopMethod, $policy),
        );

        if ($fetched->failed()) {
            return Response::error($fetched->error);
        }

        $payload = [
            'url' => $fetched->url,
            'status' => $fetched->status,
            'content_type' => $fetched->contentType,
            'content' => $fetched->content,
            'truncated' => $fetched->truncated,
        ];

        if ($fetched->title !== null) {
            $payload['title'] = $fetched->title;
        }

        if ($fetched->notes !== []) {
            $payload['notes'] = $fetched->notes;
        }

        if ($fetched->strippedHeaders !== []) {
            $payload['stripped_headers'] = $fetched->strippedHeaders;
        }

        return Response::structured($payload);
    }

    /**
     * Refuse anything that is not http or https, before the policy gate.
     *
     * Returns a message rather than a Response so the same check can be reused
     * to validate each redirect hop.
     *
     * This is a correctness bound rather than a caller preference: no legitimate
     * declared policy wants `file://`, so no policy can permit it.
     */
    private function refuseUnsupportedScheme(string $url): ?string
    {
        if (trim($url) === '') {
            return 'A URL is required.';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'The URL must be a valid http or https address.';
        }

        return null;
    }

    /**
     * The declared-policy gate. Runs before any socket is opened.
     *
     * Note what this is and is not. It makes the model's intent explicit and
     * auditable in the AiLlmMessage log, and it stops a request from reaching
     * internal services by accident. It is not a defence against a model acting
     * against the host's interest — such a model simply declares a permissive
     * policy. Keep this tool out of allowed_tools for bots taking untrusted input.
     *
     * @param array<string, mixed> $policy
     */
    private function refuseOutsidePolicy(string $url, string $method, array $policy): ?string
    {
        $allowedMethods = array_values(array_filter(array_map(
            static fn ($value): string => strtoupper(trim((string) $value)),
            (array) ($policy['allowed_methods'] ?? []),
        )));

        if ($allowedMethods === []) {
            return 'This request was not sent. You must declare request_policy.allowed_methods — a non-empty list of the '
                . 'HTTP methods you intend to use, for example {"allowed_methods": ["GET"]}. Add it and call this tool again.';
        }

        if (!in_array($method, self::SUPPORTED_METHODS, true)) {
            return sprintf(
                'The method "%s" is not supported. Use one of: %s.',
                $method,
                implode(', ', self::SUPPORTED_METHODS),
            );
        }

        if (!in_array($method, $allowedMethods, true)) {
            return sprintf(
                'This request was not sent. You asked for %s, but the request_policy you declared allows only: %s. '
                . 'Either use an allowed method, or declare %s in request_policy.allowed_methods.',
                $method,
                implode(', ', $allowedMethods),
                $method,
            );
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        $allowedHosts = array_values(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            (array) ($policy['allowed_hosts'] ?? []),
        )));

        if ($allowedHosts !== [] && !in_array($host, $allowedHosts, true)) {
            return sprintf(
                'This request was not sent. The host "%s" is not in the allowed_hosts you declared: %s.',
                $host,
                implode(', ', $allowedHosts),
            );
        }

        if (!$this->isPrivateHost($host) || ($policy['allow_private_hosts'] ?? false) === true) {
            return null;
        }

        return sprintf(
            'This request was not sent. The host "%s" resolves to a private, loopback, or link-local address, and the '
            . 'request_policy you declared does not set allow_private_hosts. Set it to true only if reaching an '
            . 'internal service is genuinely what you intend.',
            $host,
        );
    }

    /**
     * Whether a host is, or resolves to, an address on a non-public network.
     *
     * The check runs against the address resolved here, not the address the
     * connection ultimately uses, so it does not survive DNS rebinding. That is
     * a known limitation recorded in the change's design notes.
     */
    protected function isPrivateHost(string $host): bool
    {
        if ($host === '') {
            return true;
        }

        foreach ($this->addressesFor($host) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The IP addresses a host maps to — the literal itself, or its DNS records.
     *
     * A name that does not resolve is reported as private so an unresolvable
     * host fails the gate rather than slipping past it.
     *
     * Protected because it is the seam that keeps tests off the network: a test
     * subclass overrides it with a fixed host-to-address map.
     *
     * @return array<int, string>
     */
    protected function addressesFor(string $host): array
    {
        $unbracketed = trim($host, '[]');

        if (filter_var($unbracketed, FILTER_VALIDATE_IP) !== false) {
            return [$unbracketed];
        }

        if (strtolower($host) === 'localhost') {
            return ['127.0.0.1'];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false || $records === []) {
            return ['127.0.0.1'];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses === [] ? ['127.0.0.1'] : $addresses;
    }

    private function bodyFrom(Request $request): ?string
    {
        $body = $request->get('body');

        if ($body === null || $body === '') {
            return null;
        }

        return is_array($body) ? (string) json_encode($body) : (string) $body;
    }
}

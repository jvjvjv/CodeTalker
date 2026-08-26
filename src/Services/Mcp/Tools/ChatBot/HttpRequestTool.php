<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Jvjvjv\CodeTalker\Services\Web\HostGate;
use Jvjvjv\CodeTalker\Services\Web\RequestPolicy;
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
    . 'Authentication headers (Authorization, Cookie) are stripped by default; the host usually supplies '
    . 'any that are needed. If you have been given a credential to use directly (e.g. one the user provided '
    . 'in conversation), TWO THINGS ARE BOTH REQUIRED, not just one: declare '
    . 'request_policy.allow_credential_headers: true, AND add the header as its own line in the headers '
    . 'input, e.g. headers: "Authorization: Bearer <the token you were given>". Declaring '
    . 'allow_credential_headers alone sends nothing — it only lifts the block on a header you still have to '
    . 'include yourself. This also only works when this system is restricted to specific domains; it is '
    . 'refused otherwise regardless of what you declare.'
)]
class HttpRequestTool extends Tool
{
    private const SUPPORTED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private const PRIVATE_HOST_MESSAGE = 'This request was not sent. The host "%s" resolves to a private, loopback, '
        . 'or link-local address, and the request_policy you declared does not set allow_private_hosts. Set it to '
        . 'true only if reaching an internal service is genuinely what you intend.';

    public function __construct(
        protected ToolContext $context,
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
                'allow_credential_headers' => $schema->boolean()
                    ->description(
                        'Set true only when you are ALSO putting an Authorization or Cookie header in the headers '
                        . 'input on this same call — this flag by itself sends nothing; it only permits a header you '
                        . 'still have to include yourself. It is honored only when this AiSystem has been restricted '
                        . 'to specific domains by its operator — on an unrestricted system, credential headers are '
                        . 'stripped regardless of this declaration.'
                    ),
            ])
                ->description(
                    'Required. Your declared intent for this request. The request is refused when this is missing, '
                    . 'and refused again if the request falls outside what you declared here.'
                )
                ->required(),
            'body' => $schema->string()
                ->description('Optional request body, sent as-is. Set a Content-Type header to describe it.'),
            'headers' => $schema->string()
                ->description(
                    'Optional request headers, one per line, formatted as "Name: value" — for example '
                    . '"Authorization: Bearer <token>\nX-Request-Id: abc123". A plain string, not a nested object — '
                    . 'append the exact line for each header you need. Connection-management headers (Host, '
                    . 'Connection, Proxy-Authorization, ...) are always stripped. Authorization and Cookie need '
                    . 'BOTH a line here AND request_policy.allow_credential_headers: true on this same call — '
                    . 'setting only one of the two sends nothing. Check the response\'s stripped_headers field if '
                    . 'a header you expected to be sent is missing from the result.'
                ),
            'keep_html' => $schema->boolean()
                ->description('Indicate whether HTML should be kept or stripped. Only works for HTML responses.'),
            'truncate_content' => $schema->boolean()
                ->description('Indicate whether content should be truncated at ' . WebFetcher::maxContentLength() . ' bytes.'),
            'target_selector' => $schema->string()
                ->description('Selector to target; everything outside of that target_selector will be trimmed. Only works for HTML responses.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $url = trim((string) $request->get('url', ''));
        $method = strtoupper(trim((string) $request->get('method', '')));
        $declared = (array) ($request->get('request_policy') ?? []);

        // Fail closed on the declaration itself. This tool's dangerous surface is
        // methods, and a missing policy means "I don't know what you intend to
        // do" — which has no safe guess. The host checks that follow are shared
        // with fetch-web-page and live in HostGate.
        if (($refusal = $this->refuseUndeclaredPolicy($declared, $method)) !== null) {
            return Response::error($refusal);
        }

        $fetched = $this->fetcher()->request(
            method: $method,
            url: $url,
            policy: RequestPolicy::declared($declared),
            body: $this->bodyFrom($request),
            headers: $this->parseHeaderLines((string) ($request->get('headers') ?? '')),
            keepHtml: (bool) $request->get('keep_html', false),
            targetSelector: trim((string) $request->get('target_selector', '')),
            truncate: (bool) $request->get('truncate_content', true),
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
     * Overridable so tests can supply a HostGate that does not touch DNS.
     */
    protected function fetcher(): WebFetcher
    {
        return new WebFetcher(
            new HostGate(self::PRIVATE_HOST_MESSAGE),
            $this->context->botName(),
            'http-request',
            $this->context->webToolPolicy(),
        );
    }

    /**
     * Refuse a request whose policy was never declared, before anything else.
     *
     * @param array<string, mixed> $declared
     */
    private function refuseUndeclaredPolicy(array $declared, string $method): ?string
    {
        $policy = RequestPolicy::declared($declared);

        if (!$policy->restrictsMethods()) {
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

        return null;
    }

    private function bodyFrom(Request $request): ?string
    {
        $body = $request->get('body');

        if ($body === null || $body === '') {
            return null;
        }

        return is_array($body) ? (string) json_encode($body) : (string) $body;
    }

    /**
     * Parses "Name: value" lines into a header map. A flat string field is
     * dramatically more reliable for a small/local model's structured
     * function-calling output than a nested object, which was observed
     * dropping its content (an empty {} where a header was intended) even
     * when the same model correctly wrote out the equivalent JSON when just
     * asked to describe the call in prose rather than actually emit it.
     *
     * @return array<string, string>
     */
    private function parseHeaderLines(string $text): array
    {
        $headers = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $name = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));

            if ($name !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}

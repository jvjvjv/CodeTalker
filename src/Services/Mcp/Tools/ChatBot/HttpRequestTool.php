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
    . 'in conversation), declare request_policy.allow_credential_headers to send it yourself — this only '
    . 'works when this system is restricted to specific domains, and is refused otherwise.'
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
                        'Set true only when you are deliberately sending an Authorization or Cookie header you were '
                        . 'given (e.g. by the user, in conversation). Declaring this is not sufficient by itself — it '
                        . 'is honored only when this AiSystem has been restricted to specific domains by its operator. '
                        . 'On an unrestricted system, credential headers are stripped regardless of this declaration.'
                    ),
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
                    'Optional request headers. Connection-management headers (Host, Connection, Proxy-Authorization, '
                    . '...) are always stripped. Authorization and Cookie are stripped unless '
                    . 'request_policy.allow_credential_headers is set and this system permits it — see that field.'
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
            headers: (array) ($request->get('headers') ?? []),
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
}

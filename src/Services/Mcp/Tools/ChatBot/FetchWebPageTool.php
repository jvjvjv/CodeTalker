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

#[Name('fetch-web-page')]
#[Description('Fetch a web page by URL and return its readable text content using the JayScraper research user agent.')]
class FetchWebPageTool extends Tool
{
    /**
     * Remediation for a refused host, naming a field this tool's schema has.
     * It must not mention allowed_methods — fetch-web-page is GET-only and has
     * no such input, so pointing at one would be an instruction it cannot follow.
     */
    private const PRIVATE_HOST_MESSAGE = 'This page was not fetched. The host "%s" resolves to a private, loopback, '
        . 'or link-local address. Declare request_policy.allow_private_hosts as true if reaching an internal address '
        . 'is genuinely what you intend.';

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
                ->description('The full http or https URL of the web page to fetch.')
                ->required(),
            'keep_html' => $schema->boolean()
                ->description('Indicate whether HTML should be kept or stripped. Only works for HTML responses.'),
            'truncate_content' => $schema->boolean()
                ->description('Indicate whether content should be truncated at ' . WebFetcher::maxContentLength() . ' bytes.'),
            'target_selector' => $schema->string()
                ->description('Selector to target; everything outside of that target_selector will be trimmed. Only works for HTML responses.'),
            'request_policy' => $schema->object([
                'allow_private_hosts' => $schema->boolean()
                    ->description(
                        'Set true only when you deliberately intend to fetch from a loopback, link-local, or private '
                        . 'network address. Defaults to false, which refuses such hosts.'
                    ),
                'allowed_hosts' => $schema->array()
                    ->items($schema->string())
                    ->description('Optional. When given, the fetch is refused unless the URL host is in this list.'),
            ])
                ->description(
                    'Optional. Omit it to fetch public hosts only, which is what you want for an ordinary web page. '
                    . 'Declare it only to reach a private or internal address, or to pin the fetch to specific hosts.'
                ),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $declared = $request->get('request_policy');

        $fetched = $this->fetcher()->fetchPage(
            url: trim((string) $request->get('url', '')),
            // The declaration is optional; the permission never is. Absent a
            // policy this behaves exactly as an explicit allow_private_hosts: false.
            policy: is_array($declared) && $declared !== []
                ? RequestPolicy::declared($declared)
                : RequestPolicy::publicHostsOnly(),
            keepHtml: (bool) $request->get('keep_html', false),
            targetSelector: trim((string) $request->get('target_selector', '')),
            truncate: (bool) $request->get('truncate_content', true),
        );

        if ($fetched->failed()) {
            return Response::error($fetched->error);
        }

        return Response::structured([
            'url' => $fetched->url,
            'title' => $fetched->title,
            'content_type' => $fetched->contentType,
            'content' => $fetched->content,
            'truncated' => $fetched->truncated,
        ]);
    }

    /**
     * Overridable so tests can supply a HostGate that does not touch DNS.
     */
    protected function fetcher(): WebFetcher
    {
        return new WebFetcher(
            new HostGate(self::PRIVATE_HOST_MESSAGE),
            $this->context->botName(),
            'fetch-web-page',
            $this->context->webToolPolicy(),
        );
    }
}

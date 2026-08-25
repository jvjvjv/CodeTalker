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

#[Name('fetch-web-page')]
#[Description('Fetch a web page by URL and return its readable text content using the JayScraper research user agent.')]
class FetchWebPageTool extends Tool
{
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
                ->description('Indicate whether content should be truncated at ' . WebFetcher::MAX_CONTENT_LENGTH . ' bytes.'),
            'target_selector' => $schema->string()
                ->description('Selector to target; everything outside of that target_selector will be trimmed. Only works for HTML responses.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $fetched = (new WebFetcher($this->context->botName(), 'fetch-web-page'))->fetchPage(
            url: trim((string) $request->get('url', '')),
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
}

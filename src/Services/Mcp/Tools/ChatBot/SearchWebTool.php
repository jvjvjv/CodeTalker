<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb\EngineResults;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb\SearchEngineRegistry;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb\SearchQuery;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb\SearchResultsMarkdown;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('search-web')]
#[Description('Search across Bing, Google, DuckDuckGo, and Brave. Returns normalized links, descriptions, and markdown-formatted results for easy follow-up.')]
class SearchWebTool extends Tool
{
    private const DEFAULT_PAGE = 1;

    private const MAX_PAGE = 20;

    private const MIN_PER_ENGINE_LIMIT = 1;

    private const MAX_PER_ENGINE_LIMIT = 10;

    private const DEFAULT_PER_ENGINE_LIMIT = 5;

    public function __construct(
        private ToolContext $context,
    ) {}

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The search query to run.')
                ->min(1)
                ->required(),
            'engines' => $schema->array()
                ->items($schema->string()->enum(SearchEngineRegistry::SUPPORTED_ENGINES))
                ->min(1)
                ->max(4)
                ->description('Subset of engines to use. Defaults to all supported engines.'),
            'page' => $schema->integer()
                ->description('Result page number (1-20). Increase to continue searching.')
                ->min(1)
                ->max(self::MAX_PAGE)
                ->default(self::DEFAULT_PAGE),
            'per_engine_limit' => $schema->integer()
                ->description('Maximum results to return per engine (1-10).')
                ->min(self::MIN_PER_ENGINE_LIMIT)
                ->max(self::MAX_PER_ENGINE_LIMIT)
                ->default(self::DEFAULT_PER_ENGINE_LIMIT),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $term = trim((string) $request->get('query', ''));

        if ($term === '') {
            return Response::error('A non-empty query is required.');
        }

        $registry = new SearchEngineRegistry($this->context);
        $engines = $registry->normalizeRequested($request->get('engines'));
        $unsupported = $registry->unsupported($engines);

        if ($unsupported !== []) {
            return Response::error(sprintf(
                'Unsupported engines: %s. Supported engines are: %s.',
                implode(', ', $unsupported),
                implode(', ', $registry->supportedKeys()),
            ));
        }

        $query = new SearchQuery(
            term: $term,
            limit: $this->clamp(
                (int) ($request->get('per_engine_limit') ?? self::DEFAULT_PER_ENGINE_LIMIT),
                self::MIN_PER_ENGINE_LIMIT,
                self::MAX_PER_ENGINE_LIMIT,
            ),
            page: $this->clamp(
                (int) ($request->get('page') ?? self::DEFAULT_PAGE),
                self::DEFAULT_PAGE,
                self::MAX_PAGE,
            ),
        );

        $resultsByEngine = [];

        foreach ($engines as $engine) {
            $resultsByEngine[$engine] = $this->searchEngine($registry, $engine, $query);
        }

        $nextPage = min(self::MAX_PAGE, $query->page + 1);

        return Response::structured([
            'query' => $query->term,
            'page' => $query->page,
            'engines' => $engines,
            'per_engine_limit' => $query->limit,
            'results' => array_map(
                static fn (EngineResults $results): array => $results->toArray(),
                $resultsByEngine,
            ),
            'markdown' => (new SearchResultsMarkdown())->render($query, $nextPage, $engines, $resultsByEngine),
            'next_page_input' => [
                'query' => $query->term,
                'page' => $nextPage,
                'per_engine_limit' => $query->limit,
                'engines' => $engines,
            ],
            'next_actions' => [
                'Continue searching with a refined query and optional engine subset.',
                'Ask the model to fetch and inspect a specific result URL on your behalf.',
            ],
        ]);
    }

    /**
     * One engine failing must not lose the results from the others.
     */
    private function searchEngine(SearchEngineRegistry $registry, string $engine, SearchQuery $query): EngineResults
    {
        try {
            return $registry->engine($engine)->search($query);
        } catch (\Throwable $e) {
            Log::warning('search-web engine search failed', [
                'engine' => $engine,
                'query' => $query->term,
                'error' => $e->getMessage(),
            ]);

            return EngineResults::threw($engine, $e->getMessage());
        }
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}

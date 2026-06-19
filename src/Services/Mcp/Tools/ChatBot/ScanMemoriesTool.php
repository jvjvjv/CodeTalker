<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Jvjvjv\CodeTalker\Models\AiFeatureMemory;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('scan-memories')]
#[Description(
    'Search stored memories about the user for topics or keywords relevant to the current conversation. '
    . 'Use this when you need to check if you have specific context stored about the user '
    . 'that may not have been included in your initial instructions.'
)]
class ScanMemoriesTool extends Tool
{
    public function __construct(
        private ToolContext $context,
    ) {}

    /**
     * Only advertise this tool when there is a user identity to scope memories by.
     *
     * Consulted by the external MCP server when listing tools (laravel/mcp's
     * {@see \Laravel\Mcp\Server\Primitive::eligibleForRegistration()}). On the
     * MCP transport the ToolContext is built from the authenticated caller, so
     * an anonymous caller never sees this tool. This is not consulted by the
     * local chat loop, which gates tools via AiSystem::allowed_tools instead.
     */
    public function shouldRegister(): bool
    {
        return $this->context->hasIdentity();
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topics' => $schema->array()
                ->items($schema->string())
                ->min(1)
                ->max(10)
                ->description('Keywords or topics to search memories for (e.g. ["PHP", "preferred stack", "timezone"]).')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $topics = array_filter((array) ($request->get('topics') ?? []));

        if ($topics === []) {
            return Response::error('At least one topic is required.');
        }

        if (!$this->context->hasIdentity()) {
            return Response::structured([
                'memories' => [],
                'message' => 'No user identity for this conversation.',
            ]);
        }

        $query = AiFeatureMemory::query()->active();

        // The local chat loop scopes by feature; an external MCP caller has no
        // conversation, so we scan across all of the authenticated user's memories.
        if ($this->context->feature !== null) {
            $query->forFeature($this->context->feature);
        }

        if ($this->context->userId !== null) {
            $query->where('user_id', $this->context->userId);
        } else {
            $query->where('visitor_email', $this->context->visitorEmail);
        }

        $query->where(function (Builder $q) use ($topics): void {
            foreach ($topics as $topic) {
                $q->orWhere('content', 'LIKE', "%{$topic}%")
                  ->orWhere('key', 'LIKE', "%{$topic}%");
            }
        });

        $memories = $query
            ->orderByDesc('confidence')
            ->orderByDesc('times_reinforced')
            ->get(['category', 'key', 'content', 'confidence']);

        if ($memories->isEmpty()) {
            return Response::structured([
                'memories' => [],
                'message' => 'No memories found matching those topics.',
            ]);
        }

        $grouped = $memories->groupBy('category')->map(
            fn ($group) => $group->map(fn ($m) => [
                'key' => $m->key,
                'content' => $m->content,
                'confidence' => $m->confidence,
            ])->values()->toArray()
        )->toArray();

        return Response::structured(['memories' => $grouped]);
    }
}

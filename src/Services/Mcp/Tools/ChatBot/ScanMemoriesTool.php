<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot;

use Jvjvjv\CodeTalker\Contracts\Mcp\AiToolHandlerContract;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiFeatureMemory;
use Illuminate\Database\Eloquent\Builder;

class ScanMemoriesTool implements AiToolHandlerContract
{
    public function __construct(
        private AiConversation $conversation,
    ) {}

    public function name(): string
    {
        return 'scan_memories';
    }

    public function description(): string
    {
        return 'Search stored memories about the user for topics or keywords relevant to the current conversation. '
            . 'Use this when you need to check if you have specific context stored about the user '
            . 'that may not have been included in your initial instructions.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topics' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Keywords or topics to search memories for (e.g. ["PHP", "preferred stack", "timezone"]).',
                    'minItems' => 1,
                    'maxItems' => 10,
                ],
            ],
            'required' => ['topics'],
        ];
    }

    public function handle(array $input): array
    {
        $topics = array_filter((array) ($input['topics'] ?? []));

        if ($topics === []) {
            return ['error' => 'At least one topic is required.'];
        }

        $query = AiFeatureMemory::query()
            ->forFeature($this->conversation->feature)
            ->active();

        if ($this->conversation->user_id !== null) {
            $query->where('user_id', $this->conversation->user_id);
        } elseif ($this->conversation->visitor_email !== null) {
            $query->where('visitor_email', $this->conversation->visitor_email);
        } else {
            return ['memories' => [], 'message' => 'No user identity for this conversation.'];
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
            return ['memories' => [], 'message' => 'No memories found matching those topics.'];
        }

        $grouped = $memories->groupBy('category')->map(
            fn ($group) => $group->map(fn ($m) => [
                'key' => $m->key,
                'content' => $m->content,
                'confidence' => $m->confidence,
            ])->values()->toArray()
        )->toArray();

        return ['memories' => $grouped];
    }
}

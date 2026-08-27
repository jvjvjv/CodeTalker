<?php

namespace Jvjvjv\CodeTalker\Services\Management;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Jvjvjv\CodeTalker\Jobs\BackfillConversationUsageJob;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiFeatureMemory;

/**
 * Read and triage operations over stored conversations.
 *
 * System messages are excluded from every user-facing count and from search:
 * they hold the generated system prompt, which is neither something an operator
 * wrote nor something they would expect to match on.
 */
class AiConversationManager
{
    /**
     * @param array{feature?: string|null, status?: string|null, ai_system_id?: int|string|null, ai_persona_id?: int|string|null, search?: string|null} $filters
     */
    public function paginate(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $query = AiConversation::query()
            ->with($this->relations())
            ->withCount(['messages' => fn ($messages) => $messages->where('role', '!=', 'system')])
            ->orderByLastMessageAtDesc();

        if (filled($filters['feature'] ?? null)) {
            $query->where('feature', $filters['feature']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['ai_system_id'] ?? null)) {
            $query->where('ai_system_id', (int) $filters['ai_system_id']);
        }

        if (filled($filters['ai_persona_id'] ?? null)) {
            $query->where('ai_persona_id', (int) $filters['ai_persona_id']);
        }

        if (filled($filters['search'] ?? null)) {
            $this->applySearch($query, trim((string) $filters['search']));
        }

        return $query->paginate($perPage)->through(fn (AiConversation $conversation) => $this->summarize($conversation));
    }

    /**
     * A conversation with its transcript and the memories derived from it.
     *
     * @return array{conversation: array<string, mixed>, messages: Collection<int, array<string, mixed>>, memories: \Illuminate\Database\Eloquent\Collection}
     */
    public function detail(AiConversation $conversation): array
    {
        $conversation->load([...$this->relations(), 'messages']);

        $memories = AiFeatureMemory::query()
            ->where('source_conversation_id', $conversation->id)
            ->orderByDesc('confidence')
            ->get(['id', 'feature', 'category', 'key', 'content', 'confidence', 'is_active']);

        return [
            'conversation' => array_merge($this->summarize($conversation), [
                'context' => $conversation->context,
                'ai_persona' => $conversation->aiPersona ? [
                    'id' => $conversation->aiPersona->id,
                    'name' => $conversation->aiPersona->name,
                    'slug' => $conversation->aiPersona->slug,
                ] : null,
            ]),
            'messages' => $conversation->messages
                ->sortBy('created_at')
                ->values()
                ->map(static fn ($message): array => [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'metadata' => $message->metadata,
                    'created_at' => $message->created_at?->format('M j, Y g:i A'),
                ]),
            'memories' => $memories,
        ];
    }

    public function delete(AiConversation $conversation): void
    {
        $conversation->delete();
    }

    /**
     * The distinct feature keys conversations exist for.
     *
     * @return Collection<int, string>
     */
    public function features(): Collection
    {
        return AiConversation::query()->distinct()->orderBy('feature')->pluck('feature');
    }

    /**
     * Queue usage recalculation.
     *
     * @param bool $all recompute every conversation, rather than only filling
     *        in conversations that have no usage recorded at all
     */
    public function queueUsageBackfill(bool $all = false, int $chunk = 200): void
    {
        BackfillConversationUsageJob::dispatch($all, $chunk);
    }

    /**
     * Search across everything an operator might remember about a conversation:
     * what it was called, who had it, which persona served it, and what was said.
     */
    private function applySearch(mixed $query, string $search): void
    {
        $query->where(function ($builder) use ($search): void {
            $builder->where('title', 'like', '%' . $search . '%')
                ->orWhere('visitor_name', 'like', '%' . $search . '%')
                ->orWhere('visitor_email', 'like', '%' . $search . '%')
                ->orWhereHas('user', function ($userQuery) use ($search): void {
                    $userQuery->where('name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                })
                ->orWhereHas('aiPersona', function ($personaQuery) use ($search): void {
                    $personaQuery->where('name', 'like', '%' . $search . '%')
                        ->orWhere('slug', 'like', '%' . $search . '%');
                })
                ->orWhereHas('messages', function ($messageQuery) use ($search): void {
                    $messageQuery->where('role', '!=', 'system')
                        ->where('content', 'like', '%' . $search . '%');
                });
        });
    }

    /**
     * Host apps may extend AiConversation with their own relations. Loading one
     * that does not exist would throw, so the relation is included only when the
     * host's model actually defines it.
     *
     * @return array<int, string>
     */
    private function relations(): array
    {
        $relations = ['aiSystem', 'aiPersona', 'user'];

        if (method_exists(AiConversation::class, 'targetedResume')) {
            $relations[] = 'targetedResume';
        }

        return $relations;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(AiConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'chat_hash' => $conversation->chat_hash,
            'title' => $conversation->title,
            'feature' => $conversation->feature,
            // Null only for an in-memory conversation that has not round-tripped
            // through the column default.
            'status' => $conversation->status?->value,
            'updated_at' => $conversation->last_message_at?->diffForHumans()
                ?? $conversation->updated_at?->diffForHumans(),
            'messages_count' => $conversation->messages_count,
            'visitor_name' => $conversation->visitor_name,
            'visitor_email' => $conversation->visitor_email,
            'user_name' => $conversation->user?->name,
            'user_email' => $conversation->user?->email,
            'ai_system_name' => $conversation->aiSystem?->name,
            'ai_persona_name' => $conversation->aiPersona?->name,
            'ai_persona_slug' => $conversation->aiPersona?->slug,
            'usage' => [
                'input_tokens' => $conversation->usage_input_tokens,
                'output_tokens' => $conversation->usage_output_tokens,
                'total_tokens' => $conversation->usage_total_tokens,
                'cost_usd' => $conversation->usage_cost_usd !== null ? (float) $conversation->usage_cost_usd : null,
                'synced_at' => $conversation->usage_synced_at?->toIso8601String(),
            ],
            'targeted_resume' => method_exists($conversation, 'targetedResume') && $conversation->targetedResume ? [
                'id' => $conversation->targetedResume->id,
                'company_name' => $conversation->targetedResume->company_name,
                'position' => $conversation->targetedResume->position,
            ] : null,
        ];
    }
}

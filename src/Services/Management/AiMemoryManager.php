<?php

namespace Jvjvjv\CodeTalker\Services\Management;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Jvjvjv\CodeTalker\Models\AiFeatureMemory;
use Jvjvjv\CodeTalker\Services\AiMemoryService;

/**
 * Management operations over extracted feature memories.
 */
class AiMemoryManager
{
    public function __construct(
        private AiMemoryService $memories,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function createRules(): array
    {
        return array_merge([
            'feature' => ['required', 'string', 'max:50'],
        ], static::sharedRules());
    }

    /**
     * A memory's feature is fixed at creation: it scopes the memory to a
     * conversation surface, and moving one between features would inject it
     * into prompts it was never derived from.
     *
     * @return array<string, mixed>
     */
    public static function updateRules(): array
    {
        return static::sharedRules();
    }

    /**
     * @return array<string, mixed>
     */
    protected static function sharedRules(): array
    {
        return [
            'category' => ['required', 'string', 'in:preference,domain_knowledge,system_tuning'],
            'key' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'confidence' => ['required', 'integer', 'min:1', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Memories in triage order: active first, then most-confident, then
     * most-reinforced — the order someone reviewing them wants to read.
     *
     * @param array{feature?: string|null, category?: string|null, status?: string|null} $filters
     */
    public function paginate(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $query = AiFeatureMemory::query()
            ->with('sourceConversation')
            ->orderByDesc('is_active')
            ->orderByDesc('confidence')
            ->orderByDesc('times_reinforced');

        if (filled($filters['feature'] ?? null)) {
            $query->forFeature($filters['feature']);
        }

        if (filled($filters['category'] ?? null)) {
            $query->byCategory($filters['category']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('is_active', $filters['status'] === 'active');
        }

        return $query->paginate($perPage);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): AiFeatureMemory
    {
        return AiFeatureMemory::create(
            Validator::make($data, static::createRules())->validate()
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(AiFeatureMemory $memory, array $data): AiFeatureMemory
    {
        $memory->update(Validator::make($data, static::updateRules())->validate());

        return $memory;
    }

    public function delete(AiFeatureMemory $memory): void
    {
        $memory->delete();
    }

    /**
     * The distinct feature keys memories exist for.
     *
     * @return Collection<int, string>
     */
    public function features(): Collection
    {
        return AiFeatureMemory::query()->select('feature')->distinct()->pluck('feature');
    }

    /**
     * Discard a feature's memories and re-derive them from its conversations.
     */
    public function rebuild(string $feature): void
    {
        $this->memories->rebuildMemories($feature);
    }
}

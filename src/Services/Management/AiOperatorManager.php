<?php

namespace Jvjvjv\CodeTalker\Services\Management;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Jvjvjv\CodeTalker\Models\AiOperator;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Services\Mcp\ChatBotToolRegistry;

/**
 * Management operations over operators, mirroring AiPersonaManager's shape.
 */
class AiOperatorManager
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function createRules(array $data = []): array
    {
        return static::sharedRules(null);
    }

    /**
     * @param array<string, mixed> $data
     * @param AiOperator|null $operator the operator being edited, so its own
     *        slug does not collide with itself on the uniqueness check
     * @return array<string, mixed>
     */
    public static function updateRules(array $data = [], ?AiOperator $operator = null): array
    {
        return static::sharedRules($operator);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function sharedRules(?AiOperator $operator): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('ai_operators', 'slug')->ignore($operator?->id),
            ],
            'description' => ['nullable', 'string'],
            'ai_system_id' => ['required', 'integer', 'exists:ai_systems,id'],
            'prompt_template' => ['required', 'string'],
            'allowed_tools' => ['nullable', 'array'],
            'allowed_tools.*' => ['string'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): AiOperator
    {
        return AiOperator::create(
            Validator::make($data, static::createRules($data))->validate()
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(AiOperator $operator, array $data): AiOperator
    {
        $operator->update(
            Validator::make($data, static::updateRules($data, $operator))->validate()
        );

        return $operator;
    }

    public function delete(AiOperator $operator): void
    {
        $operator->delete();
    }

    /**
     * Operators with their run counts and lifetime token/cost totals, reusing
     * the same AiConversation-backed usage columns a persona's runs use.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listWithUsage(?int $aiSystemId = null): array
    {
        return AiOperator::query()
            ->with('aiSystem')
            ->withCount('conversations')
            ->withSum('conversations', 'usage_input_tokens')
            ->withSum('conversations', 'usage_output_tokens')
            ->withSum('conversations', 'usage_cost_usd')
            ->when($aiSystemId !== null, fn ($query) => $query->where('ai_system_id', $aiSystemId))
            ->orderBy('name')
            ->get()
            ->map(static fn (AiOperator $operator): array => [
                'id' => $operator->id,
                'name' => $operator->name,
                'slug' => $operator->slug,
                'description' => $operator->description,
                'is_active' => $operator->is_active,
                'ai_system' => $operator->aiSystem,
                'allowed_tools' => $operator->allowed_tools,
                'runs_count' => $operator->conversations_count,
                'usage' => $operator->conversations_sum_usage_cost_usd !== null ? [
                    'input_tokens' => (int) ($operator->conversations_sum_usage_input_tokens ?? 0),
                    'output_tokens' => (int) ($operator->conversations_sum_usage_output_tokens ?? 0),
                    'total_tokens' => null,
                    'cost_usd' => (float) $operator->conversations_sum_usage_cost_usd,
                    'synced_at' => null,
                ] : null,
            ])
            ->all();
    }

    /**
     * The tools an operator could be given, for a tool picker — mirrors
     * AiPersonaManager::availableTools().
     *
     * @return array<int, array{name: string, description: string}>
     */
    public function availableTools(?int $aiSystemId = null, bool $includeAll = false): array
    {
        $conversation = new AiConversation(['context' => []]);

        $allowedTools = null;

        if (!$includeAll && $aiSystemId !== null) {
            $allowedTools = AiSystem::query()->whereKey($aiSystemId)->value('allowed_tools');
        }

        $registry = new ChatBotToolRegistry($conversation, $allowedTools, $includeAll);

        return array_map(
            static fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'],
            ],
            $registry->toApiTools(),
        );
    }
}

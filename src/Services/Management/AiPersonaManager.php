<?php

namespace Jvjvjv\CodeTalker\Services\Management;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Jvjvjv\CodeTalker\Models\AiPersona;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\Mcp\ChatBotToolRegistry;

/**
 * Management operations over personas.
 */
class AiPersonaManager
{
    /**
     * @param array<string, mixed> $data the payload being validated, needed
     *        because the reserved-slug rule depends on the submitted access path
     * @return array<string, mixed>
     */
    public static function createRules(array $data = []): array
    {
        return static::sharedRules($data, null);
    }

    /**
     * @param array<string, mixed> $data
     * @param AiPersona|null $persona the persona being edited, so its own slug
     *        does not collide with itself on the uniqueness check
     * @return array<string, mixed>
     */
    public static function updateRules(array $data = [], ?AiPersona $persona = null): array
    {
        return static::sharedRules($data, $persona);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected static function sharedRules(array $data, ?AiPersona $persona): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('ai_personas', 'slug')->ignore($persona?->id),
                // A root-mounted persona occupies a top-level path, so its slug
                // can shadow a host application route. Reserved slugs are
                // refused only for root mounting; under /chat/ there is no
                // conflict.
                static function (string $attribute, mixed $value, Closure $fail) use ($data): void {
                    if (($data['access_path'] ?? null) === AiPersona::ACCESS_PATH_ROOT
                        && in_array((string) $value, AiPersona::reservedRootSlugs(), true)) {
                        $fail('This slug is reserved for an existing site route and cannot be used from the root path.');
                    }
                },
            ],
            'access_path' => ['required', Rule::in([AiPersona::ACCESS_PATH_CHAT, AiPersona::ACCESS_PATH_ROOT])],
            'description' => ['nullable', 'string'],
            'ai_system_id' => ['required', 'integer', 'exists:ai_systems,id'],
            'context_length' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'prompt_template' => ['required', 'string'],
            'is_active' => ['boolean'],
            'require_visitor_identity' => ['boolean'],
            'tools_enabled' => ['boolean'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): AiPersona
    {
        return AiPersona::create(
            Validator::make($data, static::createRules($data))->validate()
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(AiPersona $persona, array $data): AiPersona
    {
        $persona->update(
            Validator::make($data, static::updateRules($data, $persona))->validate()
        );

        return $persona;
    }

    public function delete(AiPersona $persona): void
    {
        $persona->delete();
    }

    /**
     * Personas with their conversation counts and lifetime token/cost totals.
     *
     * The usage block is null rather than zeroed when no conversation has a
     * recorded cost, so a persona that has never been costed is distinguishable
     * from one that genuinely cost nothing. `total_tokens` and `synced_at` are
     * always null here: neither is meaningful across an aggregate.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listWithUsage(?int $aiSystemId = null): array
    {
        return AiPersona::query()
            ->with('aiSystem')
            ->withCount('conversations')
            ->withSum('conversations', 'usage_input_tokens')
            ->withSum('conversations', 'usage_output_tokens')
            ->withSum('conversations', 'usage_cost_usd')
            ->when($aiSystemId !== null, fn ($query) => $query->where('ai_system_id', $aiSystemId))
            ->orderBy('name')
            ->get()
            ->map(static fn (AiPersona $persona): array => [
                'id' => $persona->id,
                'name' => $persona->name,
                'slug' => $persona->slug,
                'access_path' => $persona->access_path,
                'public_url' => $persona->publicPath(),
                'description' => $persona->description,
                'is_active' => $persona->is_active,
                'ai_system' => $persona->aiSystem,
                'require_visitor_identity' => $persona->require_visitor_identity,
                'conversations_count' => $persona->conversations_count,
                'tools_enabled' => $persona->tools_enabled,
                'usage' => $persona->conversations_sum_usage_cost_usd !== null ? [
                    'input_tokens' => (int) ($persona->conversations_sum_usage_input_tokens ?? 0),
                    'output_tokens' => (int) ($persona->conversations_sum_usage_output_tokens ?? 0),
                    'total_tokens' => null,
                    'cost_usd' => (float) $persona->conversations_sum_usage_cost_usd,
                    'synced_at' => null,
                ] : null,
            ])
            ->all();
    }

    /**
     * Active systems shaped for a persona form, so it can show what each
     * system would contribute if the persona inherits its settings.
     *
     * @return array<int, array{id: int, name: string, model: string, context_length: int|null, temperature: float|null, supports_tools: bool}>
     */
    public function availableSystems(): array
    {
        return AiSystem::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(static fn (AiSystem $system): array => [
                'id' => $system->id,
                'name' => $system->name,
                'model' => $system->model,
                'context_length' => $system->context_length,
                'temperature' => $system->temperature !== null ? (float) $system->temperature : null,
                'supports_tools' => (bool) $system->supports_tools,
            ])
            ->all();
    }

    /**
     * The tools the chat loop would expose, for a tool picker.
     *
     * The registry is conversation-scoped because tools resolve their identity
     * from one, but listing needs no persisted conversation — an unsaved
     * instance carries enough for discovery and filtering.
     *
     * @param bool $includeAll list every discovered tool, ignoring the system's allow-list
     * @return array<int, array{name: string, description: string}>
     */
    public function availableTools(
        ?int $aiSystemId = null,
        bool $includeAll = false,
        string|int|null $userId = null,
    ): array {
        $conversation = new AiConversation([
            'user_id' => $userId,
            'context' => [],
        ]);

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

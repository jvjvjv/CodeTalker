<?php

namespace Jvjvjv\CodeTalker\Services\Management;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Models\AiSystemFeatureDefault;
use Jvjvjv\CodeTalker\Models\AiSystemPrompt;
use Jvjvjv\CodeTalker\Services\AiSystemCapabilityService;
use Jvjvjv\CodeTalker\Services\ProviderModelsClient;

/**
 * Every write operation on an AiSystem record.
 *
 * The create/update pipeline order is load-bearing: feature defaults are not
 * columns and must be split off first; a custom prompt has to exist before the
 * system referencing it is written; JSON-string fields must be decoded before
 * they reach the model; and capability hydration reads provider/model/base_url
 * off the array, so those have to be present even on update, where they are
 * immutable.
 */
class AiSystemManager
{
    public function __construct(
        private AiSystemCapabilityService $capabilities,
        private ProviderModelsClient $providerModels,
    ) {
    }

    /**
     * @param array<string, mixed> $data the payload being validated, used to
     *        decide whether an API key is required for the chosen provider
     * @return array<string, mixed>
     */
    public static function createRules(array $data = []): array
    {
        $provider = AiProvider::tryFrom((string) ($data['provider'] ?? ''));

        return array_merge([
            'provider' => ['required', 'string', Rule::in(AiProvider::values())],
            // Local and self-hosted endpoints authenticate by network position
            // rather than a key, so requiring one would make them unusable.
            'api_key' => [
                Rule::requiredIf(static fn (): bool => $provider !== null && $provider->requiresApiKey()),
                'nullable',
                'string',
            ],
            'model' => ['required', 'string', 'max:255'],
        ], static::sharedRules());
    }

    /**
     * Update deliberately omits provider, model, and api_key: the first two are
     * immutable once a system exists, because changing them invalidates the
     * stored capability flags without any way to detect it.
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
        $featureKeys = (array) config('code-talker.feature_keys', []);

        return [
            'name' => ['required', 'string', 'max:255'],
            'model_capabilities' => ['nullable', 'array'],
            'model_capabilities.reasoning' => ['nullable', 'boolean'],
            'model_capabilities.vision' => ['nullable', 'boolean'],
            'model_capabilities.tools' => ['nullable', 'boolean'],
            // Provider-reported context lengths run far ahead of anything a
            // user would set by hand, so this ceiling is deliberately higher
            // than the one on context_length below.
            'model_capabilities.max_context_length' => ['nullable', 'integer', 'min:1', 'max:2000000'],
            'base_url' => ['nullable', 'string', 'url', 'max:255'],
            'api_version' => ['nullable', 'string', 'max:50'],
            'max_tokens' => ['required', 'integer', 'min:1', 'max:200000'],
            'context_length' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'is_active' => ['boolean'],
            'system_prompt_id' => ['nullable', 'integer', 'exists:ai_system_prompts,id'],
            'custom_system_prompt' => ['nullable', 'string'],
            'config' => ['nullable', 'json'],
            'credentials' => ['nullable', 'json'],
            'auth_type' => ['nullable', 'string', 'max:50'],
            'endpoint_type' => ['nullable', 'string', 'max:50'],
            'stream_protocol' => ['nullable', 'string', 'max:50'],
            'system_prompt_mode' => ['nullable', 'string', 'max:50'],
            'supports_tools' => ['boolean'],
            'allowed_tools' => ['nullable', 'array'],
            'allowed_tools.*' => ['string', 'max:255'],
            'web_tool_policy' => ['nullable', 'json', static::webToolPolicyRule()],
            'supports_json_mode' => ['boolean'],
            'enable_thinking' => ['nullable', 'boolean'],
            'is_local_endpoint' => ['boolean'],
            // @deprecated Slated for removal from AiSystem entirely.
            'pricing_profile' => ['nullable', 'json'],
            'feature_defaults' => ['nullable', 'array'],
            'feature_defaults.*' => $featureKeys === []
                ? ['string', 'max:255']
                : ['string', Rule::in($featureKeys)],
        ];
    }

    /**
     * Validate the decoded shape of `web_tool_policy`: an `allowed_domains`
     * list of strings and/or a `credentials` map of host => header map. The
     * `json` rule already guarantees the raw string decodes; this only checks
     * what it decodes to.
     */
    private static function webToolPolicyRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            $decoded = is_string($value) ? json_decode($value, true) : $value;

            if ($decoded === null) {
                return;
            }

            if (!is_array($decoded)) {
                $fail('The :attribute must decode to an object.');

                return;
            }

            if (array_key_exists('allowed_domains', $decoded)) {
                $domains = $decoded['allowed_domains'];

                if (!is_array($domains) || array_filter($domains, static fn (mixed $d): bool => !is_string($d)) !== []) {
                    $fail('The :attribute allowed_domains must be an array of strings.');

                    return;
                }
            }

            if (array_key_exists('credentials', $decoded)) {
                $credentials = $decoded['credentials'];

                if (!is_array($credentials)) {
                    $fail('The :attribute credentials must be an object keyed by host.');

                    return;
                }

                foreach ($credentials as $headers) {
                    if (!is_array($headers) || array_filter($headers, static fn (mixed $h): bool => !is_string($h) && !is_numeric($h)) !== []) {
                        $fail('The :attribute credentials must map each host to a header map of strings.');

                        return;
                    }
                }
            }
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): AiSystem
    {
        $data = Validator::make($data, static::createRules($data))->validate();

        $featureDefaults = $data['feature_defaults'] ?? [];
        unset($data['feature_defaults']);

        $this->resolveCustomSystemPrompt($data);
        $this->decodeJsonFields($data);
        $this->capabilities->normalizeForPersistence($data);
        $this->capabilities->hydrateForPersistence($data);

        $system = AiSystem::create($data);

        $this->syncFeatureDefaults($system, $featureDefaults);

        return $system;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(AiSystem $system, array $data): AiSystem
    {
        $data = Validator::make($data, static::updateRules())->validate();

        $featureDefaults = $data['feature_defaults'] ?? [];
        unset($data['feature_defaults']);

        $this->resolveCustomSystemPrompt($data);
        $this->decodeJsonFields($data);

        // Capability hydration resolves against the provider, model, and
        // endpoint the system already has. They are re-attached only so the
        // capability service can read them, then discarded before the write so
        // provider and model stay immutable.
        $data['provider'] = $system->provider;
        $data['model'] = $system->model;

        if (!array_key_exists('base_url', $data) || blank($data['base_url'])) {
            $data['base_url'] = $system->base_url;
        }

        $this->capabilities->normalizeForPersistence($data);
        $this->capabilities->hydrateForPersistence($data);

        unset($data['provider'], $data['model']);

        $system->update($data);

        $this->syncFeatureDefaults($system, $featureDefaults);

        return $system;
    }

    /**
     * Soft-delete a system, deactivating any personas that reference it.
     *
     * The personas are deactivated rather than deleted so the relationship —
     * and whatever conversation history hangs off it — survives.
     *
     * @return int the number of personas deactivated
     */
    public function delete(AiSystem $system): int
    {
        $personaCount = $system->personas()->count();

        if ($personaCount > 0) {
            $system->personas()->update(['is_active' => false]);
        }

        $system->delete();

        return $personaCount;
    }

    /**
     * Copy a system. Feature defaults are not copied by default: a feature has
     * exactly one default system, so copying them would silently steal every
     * claim from the original.
     */
    public function duplicate(AiSystem $system, bool $copyFeatureDefaults = false): AiSystem
    {
        $clone = $system->replicate(['id']);
        $clone->name = $system->name . ' (copy)';
        $clone->duplicated_at = now();
        $clone->save();

        if ($copyFeatureDefaults) {
            $this->syncFeatureDefaults(
                $clone,
                $system->featureDefaults()->pluck('feature')->all(),
            );
        }

        return $clone;
    }

    /**
     * Make this system the default for exactly the given features.
     *
     * A feature has one default system globally, so claiming a feature takes it
     * from whichever system currently holds it. The whole swap runs in one
     * transaction — a partial failure would otherwise leave a feature with no
     * default at all, and AgentFactory::forFeature() would start throwing.
     *
     * @param array<int, string> $features
     */
    public function syncFeatureDefaults(AiSystem $system, array $features): void
    {
        DB::transaction(function () use ($system, $features): void {
            AiSystemFeatureDefault::where('ai_system_id', $system->id)->delete();

            if ($features !== []) {
                AiSystemFeatureDefault::whereIn('feature', $features)->delete();
            }

            foreach ($features as $feature) {
                AiSystemFeatureDefault::create([
                    'ai_system_id' => $system->id,
                    'feature' => $feature,
                ]);
            }
        });
    }

    /**
     * Feature keys already claimed, optionally ignoring one system's own claims
     * so an edit form can warn only about claims it would take from others.
     *
     * @return array<int, string>
     */
    public function claimedFeatures(?AiSystem $excluding = null): array
    {
        return AiSystemFeatureDefault::query()
            ->when($excluding !== null, fn ($query) => $query->where('ai_system_id', '!=', $excluding->id))
            ->pluck('feature')
            ->all();
    }

    /**
     * List the models a provider offers, for credentials that may not belong to
     * a saved system yet — which is what a "fetch models" control needs while
     * the system is still being created.
     *
     * Provider failures propagate; the caller decides how to surface them.
     *
     * @return array<int, array{id: string, name: string, loaded: bool, max_context_length: int|null, size_bytes: int|null, capabilities: array{reasoning: bool|null, vision: bool, tools: bool|null}}>
     *
     * @throws InvalidArgumentException if the provider needs an API key and none was given
     */
    public function availableModels(AiProvider $provider, ?string $apiKey = null, ?string $baseUrl = null): array
    {
        if ($provider->requiresApiKey() && blank($apiKey)) {
            throw new InvalidArgumentException(
                "An API key is required to list models for the '{$provider->value}' provider."
            );
        }

        $models = $this->providerModels->listModels($provider, $apiKey, $baseUrl);

        return collect($models)
            ->map(static fn (array $model): array => [
                'id' => $model['id'],
                'name' => $model['display_name'] ?? $model['id'],
                'loaded' => (bool) ($model['loaded'] ?? false),
                'max_context_length' => $model['max_context_length'] ?? null,
                'size_bytes' => $model['size_bytes'] ?? null,
                'capabilities' => [
                    // Only LM Studio's model list reports capabilities at all; leaving
                    // `reasoning`/`tools` unset (rather than defaulting to false) for every
                    // other provider lets callers tell "unsupported" apart from "unknown".
                    'reasoning' => data_get($model, 'capabilities.reasoning'),
                    'vision' => (bool) data_get($model, 'capabilities.vision', false),
                    'tools' => data_get($model, 'capabilities.tools'),
                ],
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Turn free-text prompt content into a real AiSystemPrompt when no existing
     * prompt was chosen, so the system has something to reference.
     *
     * @param array<string, mixed> $data
     */
    private function resolveCustomSystemPrompt(array &$data): void
    {
        $customText = $data['custom_system_prompt'] ?? null;
        unset($data['custom_system_prompt']);

        if (!empty($data['system_prompt_id']) || empty($customText)) {
            return;
        }

        $prompt = AiSystemPrompt::create([
            'title' => mb_substr(($data['name'] ?? 'AI System') . ' Custom Prompt', 0, 64),
            'description' => 'Custom prompt',
            'content' => $customText,
        ]);

        $data['system_prompt_id'] = $prompt->id;
    }

    /**
     * These arrive as JSON strings (they are validated with the `json`
     * rule) but are cast to arrays on the model, so they have to be decoded
     * before the write or the cast stores a string of a string.
     *
     * @param array<string, mixed> $data
     */
    private function decodeJsonFields(array &$data): void
    {
        foreach (['config', 'credentials', 'pricing_profile', 'web_tool_policy'] as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                continue;
            }

            if (is_string($data[$field])) {
                $data[$field] = json_decode($data[$field], true);
            }
        }
    }
}

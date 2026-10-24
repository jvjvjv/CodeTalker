<?php

namespace Jvjvjv\CodeTalker\Services\Management;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Enums\AiSystemRole;
use Jvjvjv\CodeTalker\Enums\ThinkingLevel;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Models\AiSystemFeatureDefault;
use Jvjvjv\CodeTalker\Models\AiSystemPrompt;
use Jvjvjv\CodeTalker\Services\AiSystemCapabilityService;
use Jvjvjv\CodeTalker\Services\LaravelAi\Thinking\ThinkingResolver;
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
        private ThinkingResolver $thinking = new ThinkingResolver(),
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
        $role = AiSystemRole::tryFrom((string) ($data['role'] ?? AiSystemRole::Chat->value));
        $isVoice = $role !== null && $role !== AiSystemRole::Chat;

        // A pair the provider cannot serve is reported on `role` when one was
        // submitted, and on `provider` when the chat default was assumed.
        $pairRule = static::providerRoleRule($provider, $role);
        $roleGiven = array_key_exists('role', $data);

        return array_merge(static::sharedRules(), [
            'provider' => array_merge(
                ['required', 'string', Rule::in(AiProvider::values())],
                $roleGiven ? [] : [$pairRule],
            ),
            'role' => ['sometimes', 'string', Rule::in(AiSystemRole::values()), $pairRule],
            // Local and self-hosted endpoints authenticate by network position
            // rather than a key, so requiring one would make them unusable.
            'api_key' => [
                Rule::requiredIf(static fn (): bool => $provider !== null && $provider->requiresApiKey()),
                'nullable',
                'string',
            ],
            // A voice system may leave the model blank to use the provider's
            // default, and has no use for a token limit.
            'model' => [$isVoice ? 'nullable' : 'required', 'string', 'max:255'],
            'max_tokens' => [$isVoice ? 'nullable' : 'required', 'integer', 'min:1', 'max:200000'],
        ]);
    }

    /**
     * Update deliberately omits provider, model, and api_key: the first two are
     * immutable once a system exists, because changing them invalidates the
     * stored capability flags without any way to detect it.
     *
     * @param AiSystem|null $system the system being updated; with it, a new
     *        `role` is checked against the stored provider, and a voice
     *        system is not asked for a token limit
     * @return array<string, mixed>
     */
    public static function updateRules(?AiSystem $system = null): array
    {
        $rules = static::sharedRules();
        $rules['role'] = ['sometimes', 'string', Rule::in(AiSystemRole::values())];

        if ($system === null) {
            return $rules;
        }

        $rules['role'][] = static::providerRoleRule(AiProvider::tryFrom((string) $system->provider));

        if ($system->role !== AiSystemRole::Chat) {
            $rules['max_tokens'] = ['nullable', 'integer', 'min:1', 'max:200000'];
        }

        return $rules;
    }

    /**
     * The providers able to serve a role, for a provider chooser that follows
     * the role selected.
     *
     * @return array<int, string>
     */
    public static function providersFor(AiSystemRole $role): array
    {
        return array_values(array_map(
            static fn (AiProvider $provider): string => $provider->value,
            array_filter(AiProvider::cases(), static fn (AiProvider $provider): bool => $provider->supportsRole($role)),
        ));
    }

    /**
     * Rejects a provider/role pair laravel/ai has no gateway for. Without it
     * the record saves and fails at the first call, from inside the gateway.
     *
     * With no role given, the role is read from the value under validation.
     */
    protected static function providerRoleRule(?AiProvider $provider, ?AiSystemRole $role = null): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail) use ($provider, $role): void {
            $role ??= is_string($value) ? AiSystemRole::tryFrom($value) : null;

            if ($provider !== null && $role !== null && !$provider->supportsRole($role)) {
                $fail("The {$provider->value} provider cannot serve the {$role->value} role.");
            }
        };
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
            'credentials' => ['nullable', 'json', static::credentialsRule()],
            'auth_type' => ['nullable', 'string', 'max:50'],
            'endpoint_type' => ['nullable', 'string', 'max:50'],
            'stream_protocol' => ['nullable', 'string', 'max:50'],
            'system_prompt_mode' => ['nullable', 'string', 'max:50'],
            'supports_tools' => ['boolean'],
            'allowed_tools' => ['nullable', 'array'],
            'allowed_tools.*' => ['string', 'max:255'],
            'web_tool_policy' => ['nullable', 'json', static::webToolPolicyRule()],
            'supports_json_mode' => ['boolean'],
            'thinking_level' => ['nullable', Rule::enum(ThinkingLevel::class)],
            // @deprecated Accepted for one release and translated to
            // thinking_level when no level is submitted.
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
    /**
     * Whether a decoded JSON value is an object (or the empty value that
     * clears a field). Scalars and lists pass the `json` rule but are not a
     * shape either encrypted map can be read back as.
     */
    private static function isJsonObject(mixed $decoded): bool
    {
        return is_array($decoded) && ($decoded === [] || !array_is_list($decoded));
    }

    /**
     * `credentials` is a free-form map, so only its outer shape is checked.
     */
    private static function credentialsRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            $decoded = is_string($value) ? json_decode($value, true) : $value;

            if ($decoded !== null && !static::isJsonObject($decoded)) {
                $fail('The :attribute must decode to an object.');
            }
        };
    }

    private static function webToolPolicyRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            $decoded = is_string($value) ? json_decode($value, true) : $value;

            if ($decoded === null) {
                return;
            }

            if (!static::isJsonObject($decoded)) {
                $fail('The :attribute must decode to an object.');

                return;
            }

            // An unrecognised key is refused rather than ignored: a misspelt
            // `allowed_domains` would otherwise read back as no allow-list.
            $unknown = array_diff(array_keys($decoded), ['allowed_domains', 'credentials']);

            if ($unknown !== []) {
                $fail('The :attribute has an unknown key: ' . implode(', ', $unknown) . '.');

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
        $this->resolveThinkingLevel($data);
        $this->capabilities->normalizeForPersistence($data);
        $this->capabilities->hydrateForPersistence($data);

        // Neither column is nullable. A voice system may omit both: a blank
        // model means the provider's default, and the token limit falls back
        // to the column default.
        $data['model'] ??= '';

        if (($data['max_tokens'] ?? null) === null) {
            unset($data['max_tokens']);
        }

        $system = AiSystem::create($data);

        $this->syncFeatureDefaults($system, $featureDefaults);

        return $system;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(AiSystem $system, array $data): AiSystem
    {
        $data = Validator::make($data, static::updateRules($system))->validate();

        $featureDefaults = $data['feature_defaults'] ?? [];
        unset($data['feature_defaults']);

        $this->resolveCustomSystemPrompt($data);
        $this->decodeJsonFields($data);
        $this->resolveThinkingLevel($data);

        // Both are hidden from serialization, so an edit form cannot prefill
        // them and posts them blank. Blank therefore means "unchanged", never
        // "clear": a cleared web_tool_policy is an unrestricted one. An empty
        // object is the explicit way to clear either. This runs after the
        // decode on purpose — a JSON `null` is a string until then.
        foreach (['credentials', 'web_tool_policy'] as $secret) {
            if (array_key_exists($secret, $data) && ($data[$secret] === null || $data[$secret] === '')) {
                unset($data[$secret]);
            }
        }

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

        if (array_key_exists('max_tokens', $data) && $data['max_tokens'] === null) {
            unset($data['max_tokens']);
        }

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
     * The thinking levels a provider and model can honor, for a level selector.
     *
     * When `controls_effort` is false the package sends no reasoning parameter
     * for this pair, so the level only decides whether reasoning is shown and
     * `levels` is just `off` and `medium`: render it as a show/hide control.
     * `off` is always offered for chat, because even a model that cannot stop
     * reasoning has its reasoning hidden at `off`. A provider that cannot
     * serve chat has no levels at all.
     *
     * @param array<string, mixed>|null $modelCapabilities as stored on the system
     *        (LM Studio's `reasoning` flag decides whether its level is sent)
     * @return array{levels: array<int, string>, controls_effort: bool}
     */
    public function thinkingLevels(AiProvider|string $provider, string $model, ?array $modelCapabilities = null): array
    {
        $provider = $provider instanceof AiProvider ? $provider : AiProvider::from($provider);

        $profile = $this->thinking->profileFor($provider, new AiSystem([
            'provider' => $provider->value,
            'model' => $model,
            'model_capabilities' => $modelCapabilities,
        ]));

        if (!$profile->available) {
            return ['levels' => [], 'controls_effort' => false];
        }

        $levels = $profile->controlsEffort ? $profile->levels : [ThinkingLevel::Medium];

        return [
            'levels' => [
                ThinkingLevel::Off->value,
                ...array_map(static fn (ThinkingLevel $level): string => $level->value, $levels),
            ],
            'controls_effort' => $profile->controlsEffort,
        ];
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
    /**
     * Reconcile `thinking_level` with the deprecated `enable_thinking`. A
     * submitted level wins; a lone flag is translated. Either way both columns
     * are written to agree, so a rollback to a version that only reads
     * `enable_thinking` still sees the right value. Submitting neither leaves
     * both untouched, which on update keeps the stored level.
     *
     * @param array<string, mixed> $data
     */
    private function resolveThinkingLevel(array &$data): void
    {
        if (array_key_exists('thinking_level', $data)) {
            $level = $data['thinking_level'] instanceof ThinkingLevel || $data['thinking_level'] === null
                ? $data['thinking_level']
                : ThinkingLevel::from($data['thinking_level']);
        } elseif (array_key_exists('enable_thinking', $data)) {
            $flag = $data['enable_thinking'] === null
                ? null
                : filter_var($data['enable_thinking'], FILTER_VALIDATE_BOOLEAN);
            $level = ThinkingLevel::fromLegacyFlag($flag);
        } else {
            return;
        }

        $data['thinking_level'] = $level?->value;
        $data['enable_thinking'] = $level?->isOn();
    }

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

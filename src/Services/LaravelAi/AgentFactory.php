<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi;

use Illuminate\Support\Arr;
use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

/**
 * Builds CodeTalkerAgent instances configured from AiSystem records.
 */
class AgentFactory
{
    public function __construct(
        protected AiSystemProviderConfigurator $configurator,
    ) {
    }

    /**
     * Create an agent configured from an AiSystem model.
     *
     * @param array<int, Message> $messages
     * @param array<int, object> $tools
     */
    public function forSystem(
        AiSystem $system,
        string|Stringable $instructions = '',
        array $messages = [],
        array $tools = [],
        ?int $maxTokens = null,
        ?float $temperature = null,
        int $maxSteps = 6,
    ): CodeTalkerAgent {
        $provider = AiProvider::tryFrom($system->provider);

        if ($provider === null) {
            throw new \RuntimeException("Unsupported AI provider: {$system->provider}");
        }

        $providerName = $this->configurator->providerFor($system);

        if (($system->config['web_search_enabled'] ?? false) && $this->supportsWebSearch($provider)) {
            $tools[] = new WebSearch();
        }

        $resolvedMaxTokens = $maxTokens ?? $system->max_tokens;

        return new CodeTalkerAgent(
            providerName: $providerName,
            instructions: $instructions,
            messages: $messages,
            tools: $tools,
            model: $system->model,
            maxTokens: $resolvedMaxTokens,
            temperature: $temperature ?? ($system->temperature !== null ? (float) $system->temperature : null),
            maxSteps: $maxSteps,
            timeout: $this->timeoutFor($provider, $system),
            providerOptions: $this->providerOptionsFor($provider, $system, $resolvedMaxTokens),
            showThinking: (bool) $system->enable_thinking,
        );
    }

    /**
     * Create an agent for the default system assigned to a feature.
     *
     * @param array<int, Message> $messages
     * @param array<int, object> $tools
     */
    public function forFeature(
        string $feature,
        string|Stringable $instructions = '',
        array $messages = [],
        array $tools = [],
        ?int $maxTokens = null,
        ?float $temperature = null,
        int $maxSteps = 6,
    ): CodeTalkerAgent {
        return $this->forSystem(
            $this->systemForFeature($feature),
            $instructions,
            $messages,
            $tools,
            $maxTokens,
            $temperature,
            $maxSteps,
        );
    }

    /**
     * The system assigned to a feature, applying the same guards as forFeature().
     */
    public function systemForFeature(string $feature): AiSystem
    {
        $system = AiSystem::defaultForFeature($feature);

        if ($system === null) {
            throw new \RuntimeException("No default AI system configured for feature: {$feature}");
        }

        if (!$system->is_active) {
            throw new \RuntimeException("The default AI system for feature '{$feature}' is inactive.");
        }

        return $system;
    }

    protected function supportsWebSearch(AiProvider $provider): bool
    {
        return in_array($provider, [AiProvider::Anthropic, AiProvider::OpenAI, AiProvider::Gemini], true);
    }

    protected function timeoutFor(AiProvider $provider, AiSystem $system): int
    {
        // Local endpoints can spend minutes loading a model before responding.
        if ($provider === AiProvider::LmStudio || $system->is_local_endpoint) {
            return 600;
        }

        return 120;
    }

    /**
     * @return array<string, mixed>
     */
    protected function providerOptionsFor(AiProvider $provider, AiSystem $system, ?int $maxTokens): array
    {
        $options = [];

        if (
            $provider === AiProvider::Anthropic
            && $system->enable_thinking
            && $maxTokens !== null
            && $maxTokens > 1024
        ) {
            $options['thinking'] = ['type' => 'enabled', 'budget_tokens' => 1024];
        }

        // Best-effort: LM Studio's native REST API accepts a `reasoning`
        // request field ("off"/"low"/"medium"/"high"/"on") and errors if the
        // model doesn't support it. Only send it for models LM Studio itself
        // reported as reasoning-capable, and only as a hint — the gateway's
        // showThinking() gate is what reliably keeps disabled reasoning out
        // of the chat UI regardless of whether the model server honors this.
        if ($provider === AiProvider::LmStudio && Arr::get($system->model_capabilities ?? [], 'reasoning') === true) {
            $options['reasoning'] = $system->enable_thinking ? 'on' : 'off';
        }

        // Raw provider-parameter passthrough (e.g. lm-studio's frequency_penalty,
        // repeat_penalty, top_k, seed, stop). `model` and `messages` are excluded
        // because they carry the actual request routing/content, built just
        // before this merge — nothing legitimate ever needs to override them.
        // `stream` is excluded because a stray true would break the non-streaming
        // request path (generateTextStep never resets it the way the streaming
        // path does). `tools`/`tool_choice` are excluded because code-talker
        // builds them from the tool registry, not from system config.
        $passthrough = Arr::except(
            $system->config['provider_options'] ?? [],
            ['model', 'messages', 'stream', 'tools', 'tool_choice'],
        );

        return array_merge($options, $passthrough);
    }
}

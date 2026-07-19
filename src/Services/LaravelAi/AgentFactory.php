<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi;

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
        if (
            $provider === AiProvider::Anthropic
            && $system->enable_thinking
            && $maxTokens !== null
            && $maxTokens > 1024
        ) {
            return ['thinking' => ['type' => 'enabled', 'budget_tokens' => 1024]];
        }

        return [];
    }
}

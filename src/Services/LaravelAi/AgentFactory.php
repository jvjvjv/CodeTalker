<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi;

use Illuminate\Support\Arr;
use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Enums\AiSystemRole;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiSystemResolver;
use Jvjvjv\CodeTalker\Services\LaravelAi\Thinking\ThinkingResolver;
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
        protected AiSystemResolver $resolver = new AiSystemResolver(),
        protected ThinkingResolver $thinking = new ThinkingResolver(),
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
        // Only a role known to be wrong is refused. A system with no role
        // loaded — built by hand, or read before the role migration ran — is
        // let through, as it always was; systemForFeature() is the strict path.
        if ($system->role instanceof AiSystemRole && $system->role !== AiSystemRole::Chat) {
            throw new \RuntimeException(
                "AI system '{$system->name}' has the '{$system->role->value}' role, but 'chat' is required."
            );
        }

        $provider = AiProvider::tryFrom($system->provider);

        if ($provider === null) {
            throw new \RuntimeException("Unsupported AI provider: {$system->provider}");
        }

        $providerName = $this->configurator->providerFor($system);

        if (($system->config['web_search_enabled'] ?? false) && $this->supportsWebSearch($provider)) {
            $tools[] = new WebSearch();
        }

        $resolvedMaxTokens = $maxTokens ?? $system->max_tokens;
        $thinking = $this->thinking->resolve($provider, $system, $resolvedMaxTokens);

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
            providerOptions: $this->providerOptionsFor($system, $thinking->options),
            showThinking: $thinking->showReasoning,
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
        return $this->resolver->forFeature($feature, AiSystemRole::Chat);
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
     * @param array<string, mixed> $thinkingOptions
     * @return array<string, mixed>
     */
    protected function providerOptionsFor(AiSystem $system, array $thinkingOptions): array
    {
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

        return array_merge($thinkingOptions, $passthrough);
    }
}

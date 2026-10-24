<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi\Thinking;

use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Enums\ThinkingLevel;
use Jvjvjv\CodeTalker\Models\AiSystem;

/**
 * Turns an AiSystem's thinking level into provider options and a visibility
 * decision. Reasoning is shown only when the level is above `off`, on every
 * provider; StreamTranslator enforces that against what actually streams.
 */
class ThinkingResolver
{
    public function mapperFor(AiProvider $provider): ThinkingMapper
    {
        return match ($provider) {
            AiProvider::Anthropic => new AnthropicThinking(),
            AiProvider::OpenAI => OpenAiThinking::openAi(),
            AiProvider::Grok => OpenAiThinking::xai(),
            AiProvider::Gemini => new GeminiThinking(),
            AiProvider::LmStudio => new LmStudioThinking(),
            AiProvider::OpenAICompatible, AiProvider::ElevenLabs => new NoThinking($provider),
        };
    }

    public function profileFor(AiProvider $provider, AiSystem $system): ThinkingProfile
    {
        return $this->mapperFor($provider)->profile($system);
    }

    public function resolve(AiProvider $provider, AiSystem $system, ?int $maxTokens): ThinkingResolution
    {
        $level = $system->thinkingLevel();

        if ($level === null) {
            return new ThinkingResolution([], false);
        }

        $mapper = $this->mapperFor($provider);
        $profile = $mapper->profile($system);

        if (!$profile->controlsEffort) {
            return new ThinkingResolution([], $level->isOn() && $profile->available);
        }

        // A model that cannot stop reasoning gets its lowest effort for `off`:
        // failing the request would break a reasonable stored setting after a
        // model change, and omitting the parameter would run at the provider's
        // default effort, the opposite of what `off` asked for.
        $sent = $level === ThinkingLevel::Off && $profile->canDisable
            ? ThinkingLevel::Off
            : $profile->clamp($level === ThinkingLevel::Off ? ThinkingLevel::Minimal : $level);

        if ($sent === null) {
            return new ThinkingResolution([], false);
        }

        return new ThinkingResolution($mapper->options($sent, $system, $maxTokens), $level->isOn());
    }
}

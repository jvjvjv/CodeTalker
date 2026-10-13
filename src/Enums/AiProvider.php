<?php

namespace Jvjvjv\CodeTalker\Enums;

enum AiProvider: string
{
    case Anthropic = 'anthropic';
    case OpenAI = 'openai';
    case OpenAICompatible = 'openai-compatible';
    case LmStudio = 'lm-studio';
    case Gemini = 'gemini';
    case Grok = 'grok';
    case ElevenLabs = 'elevenlabs';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $provider): string => $provider->value, self::cases());
    }

    public function requiresApiKey(): bool
    {
        return $this !== self::OpenAICompatible && $this !== self::LmStudio;
    }

    /**
     * The laravel/ai driver that serves this provider.
     */
    public function toLaravelAiDriver(): string
    {
        return match ($this) {
            self::Anthropic => 'anthropic',
            self::OpenAI => 'openai',
            self::OpenAICompatible, self::LmStudio => 'openai-compatible',
            self::Gemini => 'gemini',
            self::Grok => 'xai',
            // laravel/ai registers ElevenLabs as `eleven`, not `elevenlabs`.
            self::ElevenLabs => 'eleven',
        };
    }

    /**
     * Whether the laravel/ai driver behind this provider implements the
     * gateway a role needs. AiSystemRoleTest checks this against the driver
     * classes themselves.
     */
    public function supportsRole(AiSystemRole $role): bool
    {
        return match ($this) {
            self::OpenAI, self::Gemini => true,
            self::ElevenLabs => $role !== AiSystemRole::Chat,
            self::Anthropic, self::Grok, self::OpenAICompatible, self::LmStudio => $role === AiSystemRole::Chat,
        };
    }
}

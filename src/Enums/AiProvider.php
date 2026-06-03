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
}

<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi\Thinking;

use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Enums\AiSystemRole;
use Jvjvjv\CodeTalker\Enums\ThinkingLevel;
use Jvjvjv\CodeTalker\Models\AiSystem;

/**
 * Providers the package sends no reasoning parameter to. Generic
 * openai-compatible endpoints share no standard for one — a host that knows
 * its endpoint's field can still send it through `config.provider_options` —
 * so the level only decides whether streamed reasoning is shown. A provider
 * that cannot serve chat has no thinking at all.
 */
class NoThinking implements ThinkingMapper
{
    public function __construct(private readonly AiProvider $provider)
    {
    }

    public function profile(AiSystem $system): ThinkingProfile
    {
        return $this->provider->supportsRole(AiSystemRole::Chat)
            ? ThinkingProfile::visibilityOnly()
            : ThinkingProfile::unavailable();
    }

    public function options(ThinkingLevel $level, AiSystem $system, ?int $maxTokens): array
    {
        return [];
    }
}

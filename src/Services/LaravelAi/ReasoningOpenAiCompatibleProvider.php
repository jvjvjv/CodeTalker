<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi;

use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;

/**
 * OpenAiCompatibleProvider wired to the reasoning-aware gateway so provider
 * "thinking" streams through to the chat UI. Registered as the openai-compatible
 * driver in CodeTalkerServiceProvider::boot().
 */
class ReasoningOpenAiCompatibleProvider extends OpenAiCompatibleProvider
{
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new ReasoningOpenAiCompatibleGateway($this->events);
    }
}

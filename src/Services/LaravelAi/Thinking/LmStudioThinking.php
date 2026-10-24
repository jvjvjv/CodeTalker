<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi\Thinking;

use Illuminate\Support\Arr;
use Jvjvjv\CodeTalker\Enums\ThinkingLevel;
use Jvjvjv\CodeTalker\Models\AiSystem;

/**
 * LM Studio's native REST API accepts a `reasoning` field ("off", "low",
 * "medium", "high") and errors if the model does not support it, so it is sent
 * only for models LM Studio itself reported as reasoning-capable. Any other
 * model gets visibility only — ReasoningOpenAiCompatibleGateway and the stream
 * translator keep its reasoning out of the turn when the level is `off`.
 */
class LmStudioThinking implements ThinkingMapper
{
    public function profile(AiSystem $system): ThinkingProfile
    {
        if (Arr::get($system->model_capabilities ?? [], 'reasoning') !== true) {
            return ThinkingProfile::visibilityOnly();
        }

        return ThinkingProfile::effort(
            [ThinkingLevel::Low, ThinkingLevel::Medium, ThinkingLevel::High],
            canDisable: true,
        );
    }

    public function options(ThinkingLevel $level, AiSystem $system, ?int $maxTokens): array
    {
        return ['reasoning' => $level->value];
    }
}

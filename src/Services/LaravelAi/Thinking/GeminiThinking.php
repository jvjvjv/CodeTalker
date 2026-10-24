<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi\Thinking;

use Jvjvjv\CodeTalker\Enums\ThinkingLevel;
use Jvjvjv\CodeTalker\Models\AiSystem;

/**
 * Gemini takes a `thinkingConfig` inside `generationConfig` (laravel/ai merges
 * provider options there), in one of two shapes:
 *
 * - Gemini 3: `thinkingLevel`. 3 Pro takes low and high, 3.1 Pro adds
 *   medium, and Flash takes minimal through high. None stops thinking
 *   entirely.
 * - Gemini 2.5: `thinkingBudget` in tokens. Flash accepts `0` to turn
 *   thinking off; Pro has a floor and cannot.
 *
 * Older models have no thinking, and an unrecognised id is treated as Gemini 3
 * Pro, the narrowest current shape. Thoughts are returned only when asked for,
 * so `includeThoughts` follows the level.
 */
class GeminiThinking implements ThinkingMapper
{
    /** @var array<string, int> */
    private const BUDGETS = [
        'minimal' => 512,
        'low' => 1024,
        'medium' => 4096,
        'high' => 8192,
        'xhigh' => 16384,
        'max' => 24576,
    ];

    public function profile(AiSystem $system): ThinkingProfile
    {
        $model = $this->model($system);

        return match (true) {
            $this->isLevelModel($model) && str_contains($model, 'flash') => ThinkingProfile::effort(
                [ThinkingLevel::Minimal, ThinkingLevel::Low, ThinkingLevel::Medium, ThinkingLevel::High],
                canDisable: false,
            ),
            str_starts_with($model, 'gemini-3.1-pro') => ThinkingProfile::effort(
                [ThinkingLevel::Low, ThinkingLevel::Medium, ThinkingLevel::High],
                canDisable: false,
            ),
            $this->isBudgetModel($model) => ThinkingProfile::effort(
                ThinkingLevel::cases(),
                canDisable: str_contains($model, 'flash'),
            ),
            $this->isLegacyModel($model) => ThinkingProfile::visibilityOnly(),
            default => ThinkingProfile::effort([ThinkingLevel::Low, ThinkingLevel::High], canDisable: false),
        };
    }

    public function options(ThinkingLevel $level, AiSystem $system, ?int $maxTokens): array
    {
        $model = $this->model($system);

        if ($this->isBudgetModel($model)) {
            return ['thinkingConfig' => $level === ThinkingLevel::Off
                ? ['thinkingBudget' => 0]
                : ['thinkingBudget' => self::BUDGETS[$level->value], 'includeThoughts' => true]];
        }

        return ['thinkingConfig' => ['thinkingLevel' => $level->value, 'includeThoughts' => true]];
    }

    private function isLevelModel(string $model): bool
    {
        return preg_match('/^gemini-[3-9]/', $model) === 1;
    }

    private function isBudgetModel(string $model): bool
    {
        return str_starts_with($model, 'gemini-2.5');
    }

    private function isLegacyModel(string $model): bool
    {
        return preg_match('/^gemini-(1|2\.0)/', $model) === 1;
    }

    private function model(AiSystem $system): string
    {
        return ModelId::normalize((string) $system->model, 'gemini-');
    }
}

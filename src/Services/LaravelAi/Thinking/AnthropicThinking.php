<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi\Thinking;

use Jvjvjv\CodeTalker\Enums\ThinkingLevel;
use Jvjvjv\CodeTalker\Models\AiSystem;

/**
 * Claude models take one of two thinking shapes, and three ways of turning
 * thinking off:
 *
 * - Claude 4.6 and later: adaptive thinking plus `output_config.effort`.
 *   A fixed `budget_tokens` is rejected with a 400 from Opus 4.7 on.
 * - Claude 4.5 and earlier, back to 3.7 Sonnet: `budget_tokens`, which must
 *   be at least 1024 and below `max_tokens`. Older Claude 3 models have no
 *   thinking, so their level only decides visibility.
 *
 * `off` is "omit `thinking`" through Opus 4.8, an explicit `disabled` on
 * Opus 5 / Sonnet 5 / Haiku 5.5 (which think when nothing is sent), and
 * impossible on Opus 5.5 / Sonnet 5.5 / Fable / Mythos. An unrecognised id is
 * treated as the newest family, the one that accepts the fewest shapes.
 */
class AnthropicThinking implements ThinkingMapper
{
    private const BUDGET = 'budget';

    private const ADAPTIVE_OMIT = 'adaptive-omit';

    private const ADAPTIVE_DISABLE = 'adaptive-disable';

    private const ALWAYS_ON = 'always-on';

    private const NONE = 'none';

    /** @var array<string, string> */
    private const FAMILIES = [
        'claude-opus-4' => self::BUDGET,
        'claude-opus-4-0' => self::BUDGET,
        'claude-opus-4-1' => self::BUDGET,
        'claude-opus-4-5' => self::BUDGET,
        'claude-sonnet-4' => self::BUDGET,
        'claude-sonnet-4-0' => self::BUDGET,
        'claude-sonnet-4-5' => self::BUDGET,
        'claude-haiku-4-5' => self::BUDGET,
        'claude-opus-4-6' => self::ADAPTIVE_OMIT,
        'claude-sonnet-4-6' => self::ADAPTIVE_OMIT,
        'claude-opus-4-7' => self::ADAPTIVE_OMIT,
        'claude-opus-4-8' => self::ADAPTIVE_OMIT,
        'claude-opus-5' => self::ADAPTIVE_DISABLE,
        'claude-sonnet-5' => self::ADAPTIVE_DISABLE,
        'claude-haiku-5-5' => self::ADAPTIVE_DISABLE,
        'claude-opus-5-5' => self::ALWAYS_ON,
        'claude-sonnet-5-5' => self::ALWAYS_ON,
    ];

    /**
     * Claude 4.6 models stop short of `xhigh`, and return a readable summary
     * by default; every later model defaults to an empty one.
     */
    private const CLAUDE_4_6 = ['claude-opus-4-6', 'claude-sonnet-4-6'];

    /** @var array<string, int> */
    private const BUDGETS = [
        'minimal' => 1024,
        'low' => 1024,
        'medium' => 4096,
        'high' => 8192,
        'xhigh' => 16384,
        'max' => 32768,
    ];

    private const MIN_BUDGET = 1024;

    public function profile(AiSystem $system): ThinkingProfile
    {
        $model = $this->model($system);

        return match ($this->family($model)) {
            self::NONE => ThinkingProfile::visibilityOnly(),
            self::BUDGET => ThinkingProfile::effort(ThinkingLevel::cases(), canDisable: true),
            self::ADAPTIVE_OMIT => ThinkingProfile::effort($this->efforts($model), canDisable: true),
            self::ADAPTIVE_DISABLE => ThinkingProfile::effort($this->efforts($model), canDisable: true),
            self::ALWAYS_ON => ThinkingProfile::effort($this->efforts($model), canDisable: false),
        };
    }

    public function options(ThinkingLevel $level, AiSystem $system, ?int $maxTokens): array
    {
        $model = $this->model($system);
        $family = $this->family($model);

        if ($family === self::BUDGET) {
            return $this->budgetOptions($level, $maxTokens);
        }

        if ($level === ThinkingLevel::Off) {
            return $family === self::ADAPTIVE_DISABLE ? ['thinking' => ['type' => 'disabled']] : [];
        }

        $thinking = ['type' => 'adaptive'];

        if (!in_array($model, self::CLAUDE_4_6, true)) {
            $thinking['display'] = 'summarized';
        }

        return [
            'thinking' => $thinking,
            'output_config' => ['effort' => $level->value],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetOptions(ThinkingLevel $level, ?int $maxTokens): array
    {
        if ($level === ThinkingLevel::Off || $maxTokens === null || $maxTokens <= self::MIN_BUDGET) {
            return [];
        }

        return [
            'thinking' => [
                'type' => 'enabled',
                'budget_tokens' => min(self::BUDGETS[$level->value], $maxTokens - 1),
            ],
        ];
    }

    /**
     * @return array<int, ThinkingLevel>
     */
    private function efforts(string $model): array
    {
        $levels = [ThinkingLevel::Low, ThinkingLevel::Medium, ThinkingLevel::High, ThinkingLevel::Max];

        if (!in_array($model, self::CLAUDE_4_6, true)) {
            $levels[] = ThinkingLevel::XHigh;
        }

        return $levels;
    }

    private function family(string $model): string
    {
        if (str_starts_with($model, 'claude-3-7')) {
            return self::BUDGET;
        }

        if (str_starts_with($model, 'claude-3')) {
            return self::NONE;
        }

        return self::FAMILIES[$model] ?? self::ALWAYS_ON;
    }

    private function model(AiSystem $system): string
    {
        return ModelId::normalize((string) $system->model, 'claude-');
    }
}

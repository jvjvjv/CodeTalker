<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi\Thinking;

use Jvjvjv\CodeTalker\Enums\ThinkingLevel;
use Jvjvjv\CodeTalker\Models\AiSystem;

/**
 * OpenAI and xAI both serve chat through the Responses API, which takes
 * `reasoning.effort`. They differ in which models accept which efforts:
 *
 * - OpenAI: GPT-5.1 and later accept `none`, which is how `off` is sent,
 *   except their Pro and Codex variants; those, GPT-5 and the o-series
 *   cannot stop reasoning. GPT-5.2 Pro starts at `medium`. Non-reasoning models
 *   (GPT-4.x, `gpt-5-chat`) reject the field, so they get visibility only.
 *   An unrecognised id gets low/medium/high, which every reasoning model takes.
 * - xAI: only grok-3-mini takes an effort (low or high). The grok-4 family
 *   always reasons and rejects the field, so it is visibility only, and so
 *   is anything unrecognised.
 *
 * OpenAI returns readable reasoning only as a summary, so one is requested
 * whenever the level is above `off`.
 */
class OpenAiThinking implements ThinkingMapper
{
    /**
     * @param array<int, array{0: string, 1: array<int, string>|null, 2: bool}> $rules
     *        Ordered `[pattern, efforts or null for no field, can disable]`, first match wins.
     * @param array{0: array<int, string>|null, 1: bool} $fallback
     */
    private function __construct(
        private readonly string $family,
        private readonly array $rules,
        private readonly array $fallback,
        private readonly bool $summaries,
    ) {
    }

    public static function openAi(): self
    {
        $standard = ['low', 'medium', 'high'];

        return new self('', [
            ['/^gpt-5-chat/', null, false],
            ['/^gpt-5-pro/', ['high'], false],
            ['/^gpt-5-codex/', $standard, false],
            // Pro and Codex variants of GPT-5.1 and later do not take `none`,
            // so they are matched before the base rules that do.
            ['/^gpt-5\.\d+-pro/', ['medium', 'high', 'xhigh'], false],
            ['/^gpt-5\.1-codex-max/', [...$standard, 'xhigh'], false],
            ['/^gpt-5\.1-codex/', $standard, false],
            ['/^gpt-5\.\d+-codex/', [...$standard, 'xhigh'], false],
            ['/^gpt-5\.1/', $standard, true],
            ['/^gpt-5\.\d+/', [...$standard, 'xhigh'], true],
            ['/^gpt-5/', ['minimal', ...$standard], false],
            ['/^o\d/', $standard, false],
            ['/^(gpt-4|gpt-3|chatgpt-)/', null, false],
        ], [$standard, false], summaries: true);
    }

    public static function xai(): self
    {
        return new self('grok-', [
            ['/^grok-3-mini/', ['low', 'high'], false],
        ], [null, false], summaries: false);
    }

    public function profile(AiSystem $system): ThinkingProfile
    {
        [$efforts, $canDisable] = $this->rule($system);

        if ($efforts === null) {
            return ThinkingProfile::visibilityOnly();
        }

        return ThinkingProfile::effort(array_map(ThinkingLevel::from(...), $efforts), $canDisable);
    }

    public function options(ThinkingLevel $level, AiSystem $system, ?int $maxTokens): array
    {
        if ($level === ThinkingLevel::Off) {
            return ['reasoning' => ['effort' => 'none']];
        }

        $reasoning = ['effort' => $level->value];

        if ($this->summaries) {
            $reasoning['summary'] = 'auto';
        }

        return ['reasoning' => $reasoning];
    }

    /**
     * @return array{0: array<int, string>|null, 1: bool}
     */
    private function rule(AiSystem $system): array
    {
        $model = ModelId::normalize((string) $system->model, $this->family);

        foreach ($this->rules as [$pattern, $efforts, $canDisable]) {
            if (preg_match($pattern, $model) === 1) {
                return [$efforts, $canDisable];
            }
        }

        return $this->fallback;
    }
}

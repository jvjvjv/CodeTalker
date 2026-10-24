<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi\Thinking;

use Jvjvjv\CodeTalker\Enums\ThinkingLevel;

/**
 * What one provider/model pair can do with a thinking level.
 *
 * - `levels`: the efforts it accepts, ascending, never including `off`.
 * - `canDisable`: whether `off` actually stops reasoning. When it cannot,
 *   ThinkingResolver sends the lowest level instead and hides the output.
 * - `controlsEffort`: false when the package sends no reasoning parameter at
 *   all, so the level only decides whether reasoning is shown.
 * - `available`: false for providers that cannot serve chat.
 */
final class ThinkingProfile
{
    /**
     * @param array<int, ThinkingLevel> $levels
     */
    private function __construct(
        public readonly array $levels,
        public readonly bool $canDisable,
        public readonly bool $controlsEffort,
        public readonly bool $available = true,
    ) {
    }

    /**
     * @param array<int, ThinkingLevel> $levels
     */
    public static function effort(array $levels, bool $canDisable): self
    {
        usort($levels, static fn (ThinkingLevel $a, ThinkingLevel $b): int => $a->ordinal() <=> $b->ordinal());

        return new self(array_values(array_filter($levels, static fn (ThinkingLevel $l): bool => $l->isOn())), $canDisable, true);
    }

    public static function visibilityOnly(): self
    {
        return new self([], true, false);
    }

    public static function unavailable(): self
    {
        return new self([], true, false, false);
    }

    /**
     * The nearest supported level: the highest at or below the request,
     * otherwise the lowest above it. Lower wins because the usual unsupported
     * request is the top of the scale, and overspending is the worse surprise.
     */
    public function clamp(ThinkingLevel $level): ?ThinkingLevel
    {
        $below = array_filter($this->levels, static fn (ThinkingLevel $l): bool => $l->ordinal() <= $level->ordinal());

        if ($below !== []) {
            return end($below);
        }

        return $this->levels[0] ?? null;
    }
}

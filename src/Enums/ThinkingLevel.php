<?php

namespace Jvjvjv\CodeTalker\Enums;

/**
 * How much reasoning an AiSystem asks its model for, on one scale shared by
 * every provider. Cases are declared in ascending order; ordinal() relies on
 * it. An unset level (null on the model) means no reasoning parameter is sent
 * at all, so the provider's own default applies.
 */
enum ThinkingLevel: string
{
    case Off = 'off';
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case XHigh = 'xhigh';
    case Max = 'max';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $level): string => $level->value, self::cases());
    }

    /**
     * Translate the deprecated `enable_thinking` flag. `true` becomes medium
     * because "thinking on" already ran at the provider default effort, which
     * is medium or high everywhere a level is accepted.
     */
    public static function fromLegacyFlag(?bool $enabled): ?self
    {
        return match ($enabled) {
            true => self::Medium,
            false => self::Off,
            null => null,
        };
    }

    public function ordinal(): int
    {
        return array_search($this, self::cases(), true);
    }

    /**
     * Whether a system at this level shows its reasoning.
     */
    public function isOn(): bool
    {
        return $this !== self::Off;
    }
}

<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi\Thinking;

use Jvjvjv\CodeTalker\Enums\ThinkingLevel;
use Jvjvjv\CodeTalker\Models\AiSystem;

/**
 * Translates a thinking level into one provider's request shape. One
 * implementation per provider; ThinkingResolver picks it and does the
 * clamping, so options() only ever sees a level the profile allows.
 */
interface ThinkingMapper
{
    public function profile(AiSystem $system): ThinkingProfile;

    /**
     * The provider-options fragment for a level. `$level` is one of the
     * profile's levels, or `off` when the profile can disable thinking.
     *
     * @return array<string, mixed>
     */
    public function options(ThinkingLevel $level, AiSystem $system, ?int $maxTokens): array;
}

<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi\Thinking;

/**
 * The outcome of resolving a system's thinking level: what to add to the
 * request, and whether the reasoning that comes back may be shown.
 */
final class ThinkingResolution
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly array $options,
        public readonly bool $showReasoning,
    ) {
    }
}

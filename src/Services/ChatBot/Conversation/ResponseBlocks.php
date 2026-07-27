<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot\Conversation;

/**
 * Accumulates a turn's streamed output into contiguous text and reasoning
 * blocks, in the order the model produced them.
 *
 * Consecutive deltas of the same kind merge into one block, so the stored
 * transcript reads as prose rather than as a list of fragments.
 */
final class ResponseBlocks
{
    /** @var array<int, array{type: string, content: string}> */
    private array $blocks = [];

    public function append(string $type, string $delta): void
    {
        $last = $this->blocks !== [] ? count($this->blocks) - 1 : null;

        if ($last !== null && $this->blocks[$last]['type'] === $type) {
            $this->blocks[$last]['content'] .= $delta;

            return;
        }

        $this->blocks[] = ['type' => $type, 'content' => $delta];
    }

    /**
     * The answer itself, with every text block run together.
     */
    public function text(): string
    {
        return collect($this->blocks)->where('type', 'text')->pluck('content')->implode('');
    }

    /**
     * The model's reasoning, with each block separated for readability.
     */
    public function reasoning(): string
    {
        return collect($this->blocks)->where('type', 'reasoning')->pluck('content')->implode("\n\n");
    }

    public function isEmpty(): bool
    {
        return $this->blocks === [];
    }

    /**
     * @return array<int, array{type: string, content: string}>|null
     */
    public function toArray(): ?array
    {
        return $this->blocks !== [] ? $this->blocks : null;
    }
}

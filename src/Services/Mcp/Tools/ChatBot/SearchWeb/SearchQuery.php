<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\SearchWeb;

/**
 * A single normalized search request, shared by every engine.
 *
 * Engines differ in how they express pagination — some take a zero-based
 * offset, some a one-based start index — so both are derived here rather than
 * recomputed per engine.
 */
final class SearchQuery
{
    public function __construct(
        public readonly string $term,
        public readonly int $limit,
        public readonly int $page,
    ) {
    }

    /**
     * Zero-based result offset for the requested page.
     */
    public function offset(): int
    {
        return max(0, ($this->page - 1) * $this->limit);
    }

    /**
     * One-based result index for the requested page.
     */
    public function start(): int
    {
        return $this->offset() + 1;
    }
}

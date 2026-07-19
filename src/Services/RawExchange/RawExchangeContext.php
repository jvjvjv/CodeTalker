<?php

namespace Jvjvjv\CodeTalker\Services\RawExchange;

class RawExchangeContext
{
    /** @var array<int, RawExchangeFrame> */
    private array $stack = [];

    public function push(RawExchangeFrame $frame): void
    {
        $this->stack[] = $frame;
    }

    public function pop(): ?RawExchangeFrame
    {
        return array_pop($this->stack);
    }

    public function current(): ?RawExchangeFrame
    {
        if ($this->stack === []) {
            return null;
        }

        return $this->stack[array_key_last($this->stack)];
    }
}

<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeContext;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeFrame;
use Jvjvjv\CodeTalker\Tests\TestCase;

class RawExchangeContextTest extends TestCase
{
    public function test_it_tracks_a_stack_of_frames(): void
    {
        $context = new RawExchangeContext();
        $this->assertNull($context->current());

        $a = new RawExchangeFrame('lm-studio', 'http://localhost:1234/v1');
        $b = new RawExchangeFrame('anthropic', 'https://api.anthropic.com/v1');

        $context->push($a);
        $this->assertSame($a, $context->current());

        $context->push($b);
        $this->assertSame($b, $context->current());

        $this->assertSame($b, $context->pop());
        $this->assertSame($a, $context->current());

        $this->assertSame($a, $context->pop());
        $this->assertNull($context->current());
        $this->assertNull($context->pop());
    }
}

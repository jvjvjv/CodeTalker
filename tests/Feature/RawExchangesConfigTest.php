<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Tests\TestCase;

class RawExchangesConfigTest extends TestCase
{
    public function test_raw_exchanges_defaults_are_present(): void
    {
        $this->assertTrue(config('code-talker.raw_exchanges.enabled'));
        $this->assertSame('lm-studio', config('code-talker.raw_exchanges.providers'));
        $this->assertSame(14, config('code-talker.raw_exchanges.retention_days'));
    }
}

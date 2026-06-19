<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\ScanMemoriesTool;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Jvjvjv\CodeTalker\Tests\TestCase;

class ScanMemoriesToolTest extends TestCase
{
    public function test_it_is_not_advertised_without_a_user_identity(): void
    {
        $tool = new ScanMemoriesTool(new ToolContext());

        $this->assertFalse($tool->shouldRegister());
    }

    public function test_it_is_advertised_for_an_authenticated_user(): void
    {
        $tool = new ScanMemoriesTool(ToolContext::forUser(42));

        $this->assertTrue($tool->shouldRegister());
    }

    public function test_it_is_advertised_for_an_identified_visitor(): void
    {
        $tool = new ScanMemoriesTool(ToolContext::forUser(null, 'visitor@example.com'));

        $this->assertTrue($tool->shouldRegister());
    }
}

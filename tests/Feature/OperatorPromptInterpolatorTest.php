<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Services\Operator\OperatorPromptInterpolator;
use Jvjvjv\CodeTalker\Tests\TestCase;
use RuntimeException;

class OperatorPromptInterpolatorTest extends TestCase
{
    public function test_a_template_with_no_placeholders_passes_through_unchanged(): void
    {
        $result = (new OperatorPromptInterpolator())->interpolate('Just do the thing.', []);

        $this->assertSame('Just do the thing.', $result);
    }

    public function test_nested_placeholders_resolve_via_dotted_paths(): void
    {
        $result = (new OperatorPromptInterpolator())->interpolate(
            'A new order was placed: {{order.total}} for {{order.customer.email}}.',
            ['order' => ['total' => 42, 'customer' => ['email' => 'a@example.com']]],
        );

        $this->assertSame('A new order was placed: 42 for a@example.com.', $result);
    }

    public function test_a_missing_placeholder_throws_before_interpolating_blank(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('order.total');

        (new OperatorPromptInterpolator())->interpolate('Total: {{order.total}}', ['order' => []]);
    }

    public function test_a_non_scalar_value_is_json_encoded(): void
    {
        $result = (new OperatorPromptInterpolator())->interpolate(
            'Items: {{order.items}}',
            ['order' => ['items' => ['a', 'b']]],
        );

        $this->assertSame('Items: ["a","b"]', $result);
    }
}

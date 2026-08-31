<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Services\LaravelAi\StreamTranslator;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

class StreamTranslatorTest extends TestCase
{
    private function streamStart(): StreamStart
    {
        return new StreamStart('id-1', 'anthropic', 'claude-sonnet-4-6', time());
    }

    private function streamEnd(string $reason, int $in = 0, int $out = 0): StreamEnd
    {
        return new StreamEnd('id-1', $reason, new Usage(promptTokens: $in, completionTokens: $out), time());
    }

    public function test_text_and_reasoning_deltas_map_to_legacy_block_events(): void
    {
        $translator = new StreamTranslator();

        $this->assertSame(
            [['type' => 'content_block_delta', 'delta' => ['text' => 'Hello']]],
            $translator->translate(new TextDelta('e1', 'm1', 'Hello', time())),
        );

        $this->assertSame(
            [['type' => 'reasoning_block_delta', 'delta' => ['reasoning' => 'Thinking...']]],
            $translator->translate(new ReasoningDelta('e2', 'r1', 'Thinking...', time())),
        );
    }

    public function test_emits_exactly_one_message_start_across_multiple_stream_starts(): void
    {
        $translator = new StreamTranslator();

        $first = $translator->translate($this->streamStart());

        $this->assertSame(
            [['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => null]]]],
            $first,
        );

        $this->assertSame([], $translator->translate($this->streamStart()));
    }

    public function test_finish_emits_terminal_pair_with_mapped_reason_and_summed_usage(): void
    {
        $translator = new StreamTranslator();

        $translator->translate($this->streamStart());
        $translator->translate($this->streamEnd('tool_calls', 100, 20));
        $translator->translate($this->streamEnd('stop', 150, 30));

        $this->assertSame([
            [
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
                'usage' => ['input_tokens' => 250, 'output_tokens' => 50],
            ],
            ['type' => 'message_stop'],
        ], $translator->finish());
    }

    public function test_reason_mapping_covers_tool_calls_and_length(): void
    {
        $toolTranslator = new StreamTranslator();
        $toolTranslator->translate($this->streamEnd('tool_calls'));
        $this->assertSame('tool_use', $toolTranslator->stopReason());

        $lengthTranslator = new StreamTranslator();
        $lengthTranslator->translate($this->streamEnd('length'));
        $this->assertSame('max_tokens', $lengthTranslator->stopReason());
        $this->assertSame('length', $lengthTranslator->lastReason());
    }

    public function test_stop_reason_is_incomplete_when_no_stream_end_was_ever_seen(): void
    {
        $translator = new StreamTranslator();

        // A turn cut off before the provider finished — the browser hung up,
        // or the duration guard tripped. Reporting 'end_turn' here made a
        // truncated turn indistinguishable from a clean one in the logs.
        $this->assertSame('incomplete', $translator->stopReason());

        $translator->translate($this->streamStart());
        $translator->translate(new TextDelta('e1', 'm1', 'I', time()));

        $this->assertSame('incomplete', $translator->stopReason());
    }

    public function test_stop_reason_is_incomplete_while_a_later_step_is_still_open(): void
    {
        $translator = new StreamTranslator();

        $translator->translate($this->streamStart());
        $translator->translate($this->streamEnd('tool_calls'));

        $this->assertSame('tool_use', $translator->stopReason());

        // The agentic loop opened another provider request that never ended:
        // the turn as a whole did not complete, whatever the last finished
        // step reported.
        $translator->translate($this->streamStart());

        $this->assertSame('incomplete', $translator->stopReason());
    }

    public function test_finish_without_any_stream_start_still_emits_message_start_first(): void
    {
        $translator = new StreamTranslator();

        $events = $translator->finish();

        $this->assertSame(['message_start', 'message_delta', 'message_stop'], array_column($events, 'type'));
    }

    public function test_tool_call_and_tool_result_events_are_not_forwarded_to_the_browser(): void
    {
        $translator = new StreamTranslator();

        $toolCall = new ToolCall('e1', new Data\ToolCall('t1', 'search-web', ['query' => 'x']), time());
        $toolResult = new ToolResult('e2', new Data\ToolResult('t1', 'search-web', ['query' => 'x'], '{"ok":true}'), true, null, time());

        $this->assertSame([], $translator->translate($toolCall));
        $this->assertSame([], $translator->translate($toolResult));
    }

    public function test_error_events_map_to_the_legacy_error_shape(): void
    {
        $translator = new StreamTranslator();

        $this->assertSame(
            [['type' => 'error', 'message' => 'Provider exploded']],
            $translator->translate(new Error('e1', 'error', 'Provider exploded', false, time())),
        );
    }
}

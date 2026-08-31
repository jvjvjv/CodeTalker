<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Events\Dispatcher;
use Jvjvjv\CodeTalker\Services\LaravelAi\ReasoningOpenAiCompatibleGateway;
use Jvjvjv\CodeTalker\Services\LaravelAi\ReasoningOpenAiCompatibleProvider;
use Jvjvjv\CodeTalker\Services\LaravelAi\Streaming\Heartbeat;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Laravel\Ai\AiManager;
use Laravel\Ai\Streaming\Events\Error as ErrorEvent;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

class ReasoningOpenAiCompatibleGatewayTest extends TestCase
{
    /**
     * Drive the protected processTextStream() with a fake SSE body and collect
     * every yielded stream event.
     *
     * @return array<int, object>
     */
    private function streamEvents(string $sse, bool $showThinking = true): array
    {
        $gateway = new class($this->app->make(Dispatcher::class)) extends ReasoningOpenAiCompatibleGateway
        {
            public function run(ReasoningOpenAiCompatibleProvider $provider, string $sse, bool $showThinking): array
            {
                return iterator_to_array(
                    $this->processTextStream('inv-1', $provider, 'qwen', Utils::streamFor($sse), $showThinking),
                    false,
                );
            }
        };

        $provider = new ReasoningOpenAiCompatibleProvider(
            ['name' => 'openai-compatible', 'driver' => 'openai-compatible'],
            $this->app->make(Dispatcher::class),
        );

        return $gateway->run($provider, $sse, $showThinking);
    }

    public function test_reasoning_is_suppressed_when_show_thinking_is_false(): void
    {
        $sse = 'data: {"model":"qwen","choices":[{"delta":{"reasoning_content":"thinking..."}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{"content":"Hello"}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{},"finish_reason":"stop"}]}' . "\n\n"
            . 'data: [DONE]' . "\n\n";

        $events = $this->streamEvents($sse, showThinking: false);

        $reasoning = array_values(array_filter($events, fn ($e) => $e instanceof ReasoningDelta));
        $text = array_values(array_filter($events, fn ($e) => $e instanceof TextDelta));

        $this->assertCount(0, $reasoning);

        // Suppressing reasoning doesn't touch the text path.
        $this->assertCount(1, $text);
        $this->assertSame('Hello', $text[0]->delta);
    }

    public function test_reasoning_content_is_emitted_as_a_reasoning_delta(): void
    {
        $sse = 'data: {"model":"qwen","choices":[{"delta":{"reasoning_content":"thinking..."}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{"content":"Hello"}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{},"finish_reason":"stop"}]}' . "\n\n"
            . 'data: [DONE]' . "\n\n";

        $events = $this->streamEvents($sse);

        $reasoning = array_values(array_filter($events, fn ($e) => $e instanceof ReasoningDelta));
        $text = array_values(array_filter($events, fn ($e) => $e instanceof TextDelta));

        $this->assertCount(1, $reasoning);
        $this->assertSame('thinking...', $reasoning[0]->delta);

        // Content path is untouched by the override.
        $this->assertCount(1, $text);
        $this->assertSame('Hello', $text[0]->delta);
    }

    public function test_tool_calls_still_stream_through_the_copied_method(): void
    {
        $sse = 'data: {"model":"qwen","choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_1","function":{"name":"search","arguments":"{}"}}]}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{},"finish_reason":"tool_calls"}]}' . "\n\n"
            . 'data: [DONE]' . "\n\n";

        $events = $this->streamEvents($sse);

        $toolCalls = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));

        $this->assertCount(1, $toolCalls);
        $this->assertSame('search', $toolCalls[0]->toolCall->name);
    }

    public function test_openai_shaped_error_frame_yields_a_detailed_error_event(): void
    {
        $sse = 'data: {"error":{"code":500,"message":"Context size has been exceeded.","type":"server_error"}}' . "\n\n";

        $events = $this->streamEvents($sse);
        $errors = array_values(array_filter($events, fn ($e) => $e instanceof ErrorEvent));

        $this->assertCount(1, $errors);
        $this->assertSame('server_error', $errors[0]->type);
        $this->assertSame('Context size has been exceeded. (server_error)', $errors[0]->message);
        $this->assertFalse($errors[0]->recoverable);
    }

    public function test_lm_studio_flat_error_frame_is_not_dropped(): void
    {
        // LM Studio's own engine-level failures arrive as a flat frame — no
        // "error" wrapper key and no "choices" — unlike OpenAI's nested shape.
        $sse = 'data: {"code":500,"message":"Context size has been exceeded.","type":"server_error"}' . "\n\n";

        $events = $this->streamEvents($sse);
        $errors = array_values(array_filter($events, fn ($e) => $e instanceof ErrorEvent));

        $this->assertCount(1, $errors);
        $this->assertSame('server_error', $errors[0]->type);
        $this->assertSame('Context size has been exceeded. (server_error)', $errors[0]->message);
    }

    public function test_the_openai_compatible_driver_resolves_to_the_reasoning_provider(): void
    {
        // The service provider's boot() registered our reasoning provider as the
        // openai-compatible driver; a config-driven provider must resolve to it.
        config()->set('ai.providers.code-talker-test', [
            'driver' => 'openai-compatible',
            'name' => 'code-talker-test',
            'url' => 'http://localhost:1234/v1',
        ]);

        $provider = $this->app->make(AiManager::class)->textProvider('code-talker-test');

        $this->assertInstanceOf(ReasoningOpenAiCompatibleProvider::class, $provider);
    }

    /**
     * A gateway exposing the protected SSE parser, so a test can drive the
     * generator one step at a time. Stepping matters: the generator suspends on
     * each yield, which is what lets a single-threaded test write the second
     * half of a frame *after* observing the heartbeat for the gap.
     */
    private function parsingGateway(): object
    {
        return new class($this->app->make(Dispatcher::class)) extends ReasoningOpenAiCompatibleGateway
        {
            public function parse($body): \Generator
            {
                return $this->parseServerSentEvents($body);
            }
        };
    }

    public function test_an_idle_gap_yields_a_heartbeat_without_losing_the_frame_that_spans_it(): void
    {
        config()->set('code-talker.conversations.heartbeat_seconds', 1);

        [$readEnd, $writeEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        // Half a frame, then silence — exactly the shape that used to be lost.
        fwrite($writeEnd, 'data: {"choices":[{"delta":{"con');

        $events = $this->parsingGateway()->parse(Utils::streamFor($readEnd));

        // Runs until the first yield: the read times out and reports a beat.
        $events->rewind();
        $this->assertInstanceOf(Heartbeat::class, $events->current());
        $this->assertSame('heartbeat', $events->current()->toArray()['type']);

        // The rest of the frame arrives after the gap and must parse intact.
        fwrite($writeEnd, 'tent":"Hello"}}]}' . "\n\n");

        $events->next();
        $this->assertSame(
            [['delta' => ['content' => 'Hello']]],
            $events->current()['choices'],
        );

        fclose($writeEnd);
        $events->next();
        $this->assertFalse($events->valid());
    }

    public function test_heartbeats_are_disabled_by_a_zero_interval(): void
    {
        config()->set('code-talker.conversations.heartbeat_seconds', 0);

        $sse = 'data: {"choices":[{"delta":{"content":"Hi"}}]}' . "\n\n"
            . 'data: [DONE]' . "\n\n";

        $parsed = iterator_to_array($this->parsingGateway()->parse(Utils::streamFor($sse)), false);

        $this->assertCount(1, $parsed);
        $this->assertSame('Hi', $parsed[0]['choices'][0]['delta']['content']);
    }

    public function test_a_body_without_a_stream_resource_falls_back_to_the_parent_parser(): void
    {
        config()->set('code-talker.conversations.heartbeat_seconds', 1);

        // A PumpStream has no underlying resource, so detaching it would leave
        // nothing to read; the parser must delegate instead. The pump returns
        // null once drained — PumpStream's contract for EOF; an empty string
        // would never flip eof() and the parent parser would spin forever.
        $body = new \GuzzleHttp\Psr7\PumpStream(function (): ?string {
            static $sent = false;

            if ($sent) {
                return null;
            }

            $sent = true;

            return 'data: {"choices":[{"delta":{"content":"Hi"}}]}' . "\n\n";
        });

        $parsed = iterator_to_array($this->parsingGateway()->parse($body), false);

        $this->assertCount(1, $parsed);
        $this->assertSame('Hi', $parsed[0]['choices'][0]['delta']['content']);
    }
}

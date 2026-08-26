<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Events\Dispatcher;
use Jvjvjv\CodeTalker\Services\LaravelAi\ReasoningOpenAiCompatibleGateway;
use Jvjvjv\CodeTalker\Services\LaravelAi\ReasoningOpenAiCompatibleProvider;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Laravel\Ai\AiManager;
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
}

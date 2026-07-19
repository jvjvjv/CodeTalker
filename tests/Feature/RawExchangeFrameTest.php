<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\LaravelAi\AiSystemProviderConfigurator;
use Jvjvjv\CodeTalker\Services\RawExchange\RawExchangeFrame;
use Jvjvjv\CodeTalker\Tests\TestCase;

class RawExchangeFrameTest extends TestCase
{
    public function test_for_system_builds_a_frame_for_an_lm_studio_system(): void
    {
        $system = new AiSystem([
            'name' => 'Local',
            'provider' => 'lm-studio',
            'model' => 'qwen/qwen3.5-9b',
            'base_url' => 'http://localhost:1234',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);
        $system->id = 99;

        $frame = RawExchangeFrame::forSystem(
            $system,
            $this->app->make(AiSystemProviderConfigurator::class),
            aiConversationId: 42,
            aiLlmMessageId: 7,
        );

        $this->assertSame('lm-studio', $frame->provider);
        $this->assertSame('http://localhost:1234/v1', $frame->baseUrl);
        $this->assertSame('qwen/qwen3.5-9b', $frame->model);
        $this->assertSame(99, $frame->aiSystemId);
        $this->assertSame(42, $frame->aiConversationId);
        $this->assertSame(7, $frame->aiLlmMessageId);
        $this->assertSame('localhost', $frame->host());
        $this->assertSame(1234, $frame->port());
    }
}

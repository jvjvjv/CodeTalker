<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Jvjvjv\CodeTalker\Models\AiPersona;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\ChatBot\ChatBotStatusResolver;
use Jvjvjv\CodeTalker\Services\ProviderModelsClient;
use Jvjvjv\CodeTalker\Tests\TestCase;

class AiModelReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    public const TRANSPORT_MESSAGE = 'cURL error 7: Failed to connect to 10.0.0.5 port 8080 for https://internal.example:8080/v1/models?key=sk-secret';

    public function test_an_unavailable_provider_reports_a_fixed_message_and_logs_the_exception(): void
    {
        $system = AiSystem::create([
            'name' => 'Test System',
            'provider' => 'openai',
            'api_key' => 'sk-secret',
            'model' => 'gpt-4o',
            'max_tokens' => 1024,
            'is_active' => true,
        ]);

        AiPersona::create([
            'ai_system_id' => $system->id,
            'name' => 'Test Bot',
            'slug' => 'test-bot',
            'prompt_template' => 'You are {{persona_name}}.',
            'is_active' => true,
        ]);

        $this->app->instance(ProviderModelsClient::class, new class extends ProviderModelsClient {
            public function listModelsForSystem(AiSystem $system): array
            {
                throw new ConnectionException(AiModelReadinessServiceTest::TRANSPORT_MESSAGE);
            }
        });

        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = ['message' => $event->message, 'context' => $event->context];
        });

        $statuses = $this->app->make(ChatBotStatusResolver::class)->statusesBySlug();

        $this->assertSame('unavailable', $statuses['test-bot']['state']);
        $this->assertSame('Provider is unavailable.', $statuses['test-bot']['message']);

        $payload = json_encode($statuses);
        $this->assertStringNotContainsString('internal.example', $payload);
        $this->assertStringNotContainsString('10.0.0.5', $payload);
        $this->assertStringNotContainsString('sk-secret', $payload);
        $this->assertStringNotContainsString('cURL', $payload);

        $warning = collect($logged)->firstWhere('message', 'AI system readiness check failed');
        $this->assertNotNull($warning);
        // The log keeps what diagnoses the failure and masks the key.
        $this->assertSame(ConnectionException::class, $warning['context']['exception']);
        $this->assertStringContainsString('internal.example:8080', $warning['context']['error']);
        $this->assertStringNotContainsString('sk-secret', json_encode($warning['context']));
    }

    public function test_a_failed_warmup_reports_a_fixed_message_and_logs_the_detail_masked(): void
    {
        \Illuminate\Support\Facades\Http::fake(fn () => throw new ConnectionException(
            'cURL error 7: Failed to connect for http://user:pw-secret@local.test:1234/v1/x?api_key=k-secret'
        ));

        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = ['message' => $event->message, 'context' => $event->context];
        });

        $readiness = $this->app->make(\Jvjvjv\CodeTalker\Services\AiModelReadinessService::class);

        foreach (['lm-studio' => 'Model load failed.', 'openai-compatible' => 'Warmup failed.'] as $provider => $expected) {
            $system = AiSystem::create([
                'name' => "Local {$provider}",
                'provider' => $provider,
                'api_key' => 'local-key',
                'model' => 'qwen3',
                'base_url' => 'http://local.test:1234/v1',
                'max_tokens' => 1024,
                'is_active' => true,
            ]);

            $status = $readiness->warmUpSystem($system);

            $this->assertSame('unavailable', $status['state'], $provider);
            $this->assertSame($expected, $status['message'], $provider);
            $this->assertTrue($status['warmup_attempted'], $provider);
        }

        $warmupLogs = collect($logged)->whereIn('message', ['AI system model load failed', 'AI system warmup failed']);
        $this->assertCount(2, $warmupLogs);

        $encoded = json_encode($warmupLogs->all());
        $this->assertStringContainsString('local.test:1234', $encoded);
        $this->assertStringNotContainsString('pw-secret', $encoded);
        $this->assertStringNotContainsString('k-secret', $encoded);
    }
}

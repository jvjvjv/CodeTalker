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
        $this->assertInstanceOf(ConnectionException::class, $warning['context']['exception']);
        $this->assertSame(self::TRANSPORT_MESSAGE, $warning['context']['exception']->getMessage());
    }
}

<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Jvjvjv\CodeTalker\Tests\TestCase;

class PruneProviderExchangesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_rows_older_than_retention_days(): void
    {
        config()->set('code-talker.raw_exchanges.retention_days', 14);

        $old = AiProviderExchange::create([
            'provider' => 'lm-studio', 'endpoint' => '/v1/chat/completions', 'method' => 'POST',
            'streaming' => false, 'created_at' => now()->subDays(30),
        ]);
        $recent = AiProviderExchange::create([
            'provider' => 'lm-studio', 'endpoint' => '/v1/chat/completions', 'method' => 'POST',
            'streaming' => false, 'created_at' => now()->subDays(2),
        ]);

        $this->artisan('ai:prune-provider-exchanges')
            ->assertExitCode(0);

        $this->assertNull(AiProviderExchange::find($old->id));
        $this->assertNotNull(AiProviderExchange::find($recent->id));
    }

    public function test_dry_run_deletes_nothing(): void
    {
        AiProviderExchange::create([
            'provider' => 'lm-studio', 'endpoint' => '/v1/chat/completions', 'method' => 'POST',
            'streaming' => false, 'created_at' => now()->subDays(90),
        ]);

        $this->artisan('ai:prune-provider-exchanges --dry-run')->assertExitCode(0);

        $this->assertSame(1, AiProviderExchange::count());
    }
}

<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Jvjvjv\CodeTalker\Models\AiMcpServer;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\Management\AiMcpServerManager;
use Jvjvjv\CodeTalker\Tests\Support\FakeMcpServer;
use Jvjvjv\CodeTalker\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AiMcpServerManagerTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://mdn.test/mcp';

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    private function manager(): AiMcpServerManager
    {
        return $this->app->make(AiMcpServerManager::class);
    }

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'mdn',
            'name' => 'MDN',
            'url' => self::URL,
            'auth' => ['type' => 'none'],
        ], $overrides);
    }

    public function test_create_stores_the_server_and_syncs_its_catalog(): void
    {
        (new FakeMcpServer(self::URL, [FakeMcpServer::tool('search')]))->fake();

        $result = $this->manager()->create($this->validData());

        $this->assertSame('http', $result['server']->transport);
        $this->assertSame(['server' => 'mdn', 'stored' => 1, 'unrepresentable' => 0, 'error' => null], $result['sync']);
        $this->assertSame(['mdn__search'], $result['server']->tools()->pluck('exposed_name')->all());
    }

    public function test_a_failed_sync_on_create_is_reported_not_thrown(): void
    {
        (new FakeMcpServer(self::URL))->down()->fake();

        $result = $this->manager()->create($this->validData());

        $this->assertTrue($result['server']->exists);
        $this->assertNotNull($result['sync']['error']);
        $this->assertNotNull($result['server']->fresh()->last_sync_error);
    }

    public function test_auth_supplied_as_a_json_string_is_decoded(): void
    {
        (new FakeMcpServer(self::URL))->fake();

        $result = $this->manager()->create($this->validData([
            'auth' => json_encode(['type' => 'bearer', 'token' => 'tok']),
        ]));

        $this->assertSame(['type' => 'bearer', 'token' => 'tok'], $result['server']->fresh()->auth);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidInput(): array
    {
        return [
            'slug with underscores' => [['slug' => 'my_docs'], 'slug'],
            'slug too long' => [['slug' => str_repeat('a', 25)], 'slug'],
            'non-http url' => [['url' => 'ftp://mdn.test/mcp'], 'url'],
            'stdio transport' => [['transport' => 'stdio'], 'transport'],
            'unknown auth type' => [['auth' => ['type' => 'magic']], 'auth'],
            'bearer without token' => [['auth' => ['type' => 'bearer']], 'auth'],
            'headers not a map of strings' => [['auth' => ['type' => 'headers', 'headers' => ['X' => ['nested']]]], 'auth'],
            'client credentials without client id' => [['auth' => ['type' => 'client_credentials']], 'auth'],
            'auth json that is not an object' => [['auth' => '"none"'], 'auth'],
            'timeout below range' => [['timeout_seconds' => 0], 'timeout_seconds'],
            'timeout above range' => [['timeout_seconds' => 301], 'timeout_seconds'],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_rules_reject_invalid_input(array $overrides, string $field): void
    {
        $validator = Validator::make($this->validData($overrides), AiMcpServerManager::createRules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey($field, $validator->errors()->toArray());
    }

    public function test_rules_accept_every_supported_auth_shape(): void
    {
        foreach ([
            ['type' => 'none'],
            ['type' => 'bearer', 'token' => 't'],
            ['type' => 'headers', 'headers' => ['X-Api-Key' => 'k']],
            ['type' => 'client_credentials', 'client_id' => 'c', 'client_secret' => 's', 'token_endpoint' => 'https://auth.test/token'],
        ] as $auth) {
            $this->assertFalse(
                Validator::make($this->validData(['auth' => $auth]), AiMcpServerManager::createRules())->fails(),
                'Rejected auth type ' . $auth['type'],
            );
        }
    }

    public function test_a_duplicate_slug_is_rejected(): void
    {
        (new FakeMcpServer(self::URL))->fake();
        $this->manager()->create($this->validData());

        $this->expectException(ValidationException::class);

        $this->manager()->create($this->validData());
    }

    public function test_the_slug_is_immutable_and_omitted_auth_is_kept(): void
    {
        (new FakeMcpServer(self::URL))->fake();
        $server = $this->manager()->create($this->validData([
            'auth' => ['type' => 'bearer', 'token' => 'keep-me'],
        ]))['server'];

        $this->manager()->update($server, ['slug' => 'renamed', 'name' => 'MDN Docs']);

        $server->refresh();
        $this->assertSame('mdn', $server->slug);
        $this->assertSame('MDN Docs', $server->name);
        $this->assertSame('keep-me', $server->auth['token']);
    }

    public function test_sync_all_reports_each_enabled_server_independently(): void
    {
        (new FakeMcpServer(self::URL, [FakeMcpServer::tool('search')]))->fake();
        (new FakeMcpServer('https://down.test/mcp'))->down()->fake();

        AiMcpServer::create(['slug' => 'mdn', 'name' => 'MDN', 'url' => self::URL, 'enabled' => true]);
        AiMcpServer::create(['slug' => 'down', 'name' => 'Down', 'url' => 'https://down.test/mcp', 'enabled' => true]);
        AiMcpServer::create(['slug' => 'off', 'name' => 'Off', 'url' => 'https://off.test/mcp', 'enabled' => false]);

        $reports = collect($this->manager()->syncAll())->keyBy('server');

        $this->assertEqualsCanonicalizing(['mdn', 'down'], $reports->keys()->all());
        $this->assertSame(1, $reports['mdn']['stored']);
        $this->assertNull($reports['mdn']['error']);
        $this->assertNotNull($reports['down']['error']);
    }

    public function test_delete_reports_systems_that_granted_its_tools_and_leaves_their_grants(): void
    {
        (new FakeMcpServer(self::URL, [FakeMcpServer::tool('search')]))->fake();
        $server = $this->manager()->create($this->validData())['server'];

        $system = fn (string $name, array $tools) => AiSystem::create([
            'name' => $name,
            'provider' => 'anthropic',
            'api_key' => 'k',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
            'allowed_tools' => $tools,
        ]);

        $granted = $system('Granted', ['fetch-web-page', 'mdn__search']);
        $system('Other server', ['mdnx__search']);
        $system('None', ['fetch-web-page']);

        $this->assertSame(1, $this->manager()->delete($server));

        $this->assertSoftDeleted($server);
        $this->assertSame(['fetch-web-page', 'mdn__search'], $granted->fresh()->allowed_tools);
    }

    public function test_list_shows_sync_health_and_tools_without_auth(): void
    {
        (new FakeMcpServer(self::URL, [
            FakeMcpServer::tool('search'),
            ['name' => 'weird', 'inputSchema' => ['type' => 'string']],
        ]))->fake();
        $this->manager()->create($this->validData(['auth' => ['type' => 'bearer', 'token' => 'secret']]));

        $listed = $this->manager()->list()[0];

        $this->assertArrayNotHasKey('auth', $listed);
        $this->assertStringNotContainsString('secret', json_encode($listed));
        $this->assertSame('bearer', $listed['auth_type']);
        $this->assertNotNull($listed['last_synced_at']);

        $tools = collect($listed['tools'])->keyBy('remote_name');
        $this->assertTrue($tools['search']['available']);
        $this->assertSame('mdn__search', $tools['search']['exposed_name']);
        $this->assertFalse($tools['weird']['available']);
        $this->assertNotNull($tools['weird']['unavailable_reason']);
    }

    // ---------------------------------------------------------------- schedule

    public function test_the_daily_sync_is_scheduled_with_the_other_package_jobs(): void
    {
        // TestCase turns the schedule off; boot a provider with it on.
        config(['code-talker.schedule' => true]);
        (new \Jvjvjv\CodeTalker\CodeTalkerServiceProvider($this->app))->boot();

        $commands = collect($this->app->make(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command);

        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'ai:sync-mcp-servers')));
    }

    // ---------------------------------------------------------------- command

    public function test_the_sync_command_syncs_and_fails_on_an_outage(): void
    {
        (new FakeMcpServer(self::URL, [FakeMcpServer::tool('search')]))->fake();
        (new FakeMcpServer('https://down.test/mcp'))->down()->fake();

        AiMcpServer::create(['slug' => 'mdn', 'name' => 'MDN', 'url' => self::URL, 'enabled' => true]);

        $this->artisan('ai:sync-mcp-servers')->assertSuccessful();
        $this->artisan('ai:sync-mcp-servers', ['slug' => 'mdn'])->assertSuccessful();
        $this->artisan('ai:sync-mcp-servers', ['slug' => 'missing'])->assertFailed();

        AiMcpServer::create(['slug' => 'down', 'name' => 'Down', 'url' => 'https://down.test/mcp', 'enabled' => true]);

        $this->artisan('ai:sync-mcp-servers')->assertFailed();
    }
}

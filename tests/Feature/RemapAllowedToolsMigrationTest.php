<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jvjvjv\CodeTalker\Tests\TestCase;

class RemapAllowedToolsMigrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Stand up just the ai_systems table the remap migration touches, avoiding
        // the rest of the package migration set (which depends on a host User model).
        Schema::create('ai_systems', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('provider');
            $table->text('api_key');
            $table->string('model');
            $table->json('allowed_tools')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_systems');

        parent::tearDown();
    }

    private function migration(): Migration
    {
        return require __DIR__ . '/../../database/migrations/2026_06_19_000000_remap_allowed_tools_to_kebab_case.php';
    }

    private function insertSystem(?array $allowedTools): int
    {
        return DB::table('ai_systems')->insertGetId([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'secret',
            'model' => 'claude-sonnet-4-6',
            'allowed_tools' => $allowedTools === null ? null : json_encode($allowedTools),
        ]);
    }

    private function allowedTools(int $id): ?array
    {
        $value = DB::table('ai_systems')->where('id', $id)->value('allowed_tools');

        return $value === null ? null : json_decode((string) $value, true);
    }

    public function test_up_remaps_known_names_and_preserves_host_app_names(): void
    {
        $id = $this->insertSystem(['fetch_web_page', 'search_web', 'scan_memories', 'my_custom_tool']);

        $this->migration()->up();

        $this->assertSame(
            ['fetch-web-page', 'search-web', 'scan-memories', 'my_custom_tool'],
            $this->allowedTools($id),
        );
    }

    public function test_up_is_idempotent_and_leaves_null_rows_alone(): void
    {
        $renamed = $this->insertSystem(['fetch-web-page']);
        $null = $this->insertSystem(null);

        $this->migration()->up();

        $this->assertSame(['fetch-web-page'], $this->allowedTools($renamed));
        $this->assertNull($this->allowedTools($null));
    }

    public function test_down_reverses_the_rename(): void
    {
        $id = $this->insertSystem(['fetch-web-page', 'search-web', 'scan-memories']);

        $this->migration()->down();

        $this->assertSame(
            ['fetch_web_page', 'search_web', 'scan_memories'],
            $this->allowedTools($id),
        );
    }
}

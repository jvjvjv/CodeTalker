<?php

namespace Jvjvjv\CodeTalker\Console\Commands;

use Illuminate\Console\Command;
use Jvjvjv\CodeTalker\Models\AiMcpServer;
use Jvjvjv\CodeTalker\Services\Management\AiMcpServerManager;

class SyncMcpServersCommand extends Command
{
    protected $signature = 'ai:sync-mcp-servers {slug? : Sync only this server}';

    protected $description = 'Refresh the stored tool catalogs of remote MCP servers';

    public function handle(AiMcpServerManager $manager): int
    {
        $slug = $this->argument('slug');

        if ($slug !== null) {
            $server = AiMcpServer::query()->where('slug', $slug)->first();

            if ($server === null) {
                $this->error("No MCP server with slug [{$slug}].");

                return self::FAILURE;
            }

            $reports = [$manager->sync($server)];
        } else {
            $reports = $manager->syncAll();
        }

        if ($reports === []) {
            $this->info('No enabled MCP servers to sync.');

            return self::SUCCESS;
        }

        $this->table(
            ['Server', 'Tools', 'Unavailable', 'Error'],
            array_map(static fn (array $report): array => [
                $report['server'],
                $report['stored'],
                $report['unrepresentable'],
                $report['error'] ?? '',
            ], $reports),
        );

        // Non-zero so a scheduler or cron wrapper can alert on an outage.
        return collect($reports)->contains(static fn (array $report): bool => $report['error'] !== null)
            ? self::FAILURE
            : self::SUCCESS;
    }
}

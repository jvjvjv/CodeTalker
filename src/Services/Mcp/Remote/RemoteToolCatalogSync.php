<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Remote;

use Illuminate\Support\Facades\DB;
use Jvjvjv\CodeTalker\Events\RemoteMcpToolDefinitionChanged;
use Jvjvjv\CodeTalker\Models\AiMcpServer;
use Jvjvjv\CodeTalker\Models\AiMcpServerTool;
use Laravel\Mcp\Client\Primitives\Tool as RemoteTool;
use Throwable;

/**
 * Lists a server's tools and stores them as its catalog.
 *
 * Turns never list tools themselves; they read what this stored. So the
 * listing is fetched in full before anything is written, and the catalog is
 * replaced in one transaction: a sync that fails partway leaves the previous
 * catalog serving turns rather than a truncated one.
 */
class RemoteToolCatalogSync
{
    public function __construct(
        private readonly RemoteMcpClientFactory $clients,
    ) {
    }

    /**
     * @return array{server: string, stored: int, unrepresentable: int, error: ?string}
     */
    public function sync(AiMcpServer $server): array
    {
        $client = null;

        try {
            $client = $this->clients->make($server);
            $remoteTools = $client->tools()->values()->all();
        } catch (Throwable $e) {
            $server->forceFill(['last_sync_error' => $e->getMessage()])->save();

            return $this->report($server, 0, 0, $e->getMessage());
        } finally {
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // The listing is already in hand, or the sync already failed.
            }
        }

        $changed = [];
        $unrepresentable = 0;

        DB::transaction(function () use ($server, $remoteTools, &$changed, &$unrepresentable): void {
            $existing = $server->tools()->get()->keyBy('remote_name');
            $seenExposed = [];
            $seenRemote = [];
            $now = now();

            foreach ($remoteTools as $remoteTool) {
                /** @var RemoteTool $remoteTool */
                $attributes = $this->attributesFor($server, $remoteTool, $seenExposed);
                $attributes['synced_at'] = $now;
                $seenRemote[] = $remoteTool->name;

                if (!$attributes['representable']) {
                    $unrepresentable++;
                }

                $row = $existing->get($remoteTool->name);

                if ($row === null) {
                    $server->tools()->create($attributes + ['remote_name' => $remoteTool->name]);

                    continue;
                }

                $previousHash = $row->definition_hash;
                $row->fill($attributes)->save();

                if ($previousHash !== $row->definition_hash) {
                    $changed[] = [$row, $previousHash];
                }
            }

            $server->tools()->whereNotIn('remote_name', $seenRemote)->delete();

            $server->forceFill([
                'last_synced_at' => $now,
                'last_sync_error' => null,
            ])->save();
        });

        foreach ($changed as [$row, $previousHash]) {
            RemoteMcpToolDefinitionChanged::dispatch($server, $row, $previousHash);
        }

        return $this->report($server, count($remoteTools), $unrepresentable, null);
    }

    /**
     * @param array<string, true> $seenExposed exposed names already taken on this server
     * @return array<string, mixed>
     */
    private function attributesFor(AiMcpServer $server, RemoteTool $remoteTool, array &$seenExposed): array
    {
        $exposed = ExposedToolName::for($server->slug, $remoteTool->name);
        $reason = RemoteToolSchema::unrepresentableReason($remoteTool->inputSchema);

        if (isset($seenExposed[$exposed])) {
            // Two remote names sanitized to the same exposed name. The first
            // keeps it; this one is stored for visibility but ungrantable.
            $reason = "Its exposed name {$exposed} collides with another tool on this server.";
            $exposed = null;
        } else {
            $seenExposed[$exposed] = true;
        }

        return [
            'exposed_name' => $exposed,
            'title' => $remoteTool->title,
            'description' => $remoteTool->description,
            'input_schema' => $remoteTool->inputSchema,
            'annotations' => $remoteTool->annotations,
            'representable' => $reason === null,
            'unrepresentable_reason' => $reason,
            'definition_hash' => sha1((string) json_encode([
                $remoteTool->title,
                $remoteTool->description,
                $remoteTool->inputSchema,
                $remoteTool->annotations,
            ])),
        ];
    }

    /**
     * @return array{server: string, stored: int, unrepresentable: int, error: ?string}
     */
    private function report(AiMcpServer $server, int $stored, int $unrepresentable, ?string $error): array
    {
        return [
            'server' => $server->slug,
            'stored' => $stored,
            'unrepresentable' => $unrepresentable,
            'error' => $error,
        ];
    }
}

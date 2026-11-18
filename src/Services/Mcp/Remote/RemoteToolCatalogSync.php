<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Remote;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Jvjvjv\CodeTalker\Events\RemoteMcpToolDefinitionChanged;
use Jvjvjv\CodeTalker\Models\AiMcpServer;
use Jvjvjv\CodeTalker\Models\AiMcpServerTool;
use Jvjvjv\CodeTalker\Services\Web\WebFetcher;
use Laravel\Mcp\Client\Primitives\Tool as RemoteTool;
use Throwable;

/**
 * Lists a server's tools and stores them as its catalog.
 *
 * Turns never list tools themselves; they read what this stored. So the
 * listing is fetched in full before anything is written, and the catalog is
 * replaced in one transaction: a sync that fails partway leaves the previous
 * catalog serving turns rather than a truncated one.
 *
 * sync() never throws. A failure at any stage — the listing, a bound being
 * exceeded, or the write itself — is recorded as the server's last sync error
 * and returned in the report, because callers include a manager's create and
 * update, which must save a server that is down right now.
 */
class RemoteToolCatalogSync
{
    private const DEFAULT_MAX_TOOLS = 500;

    /** The length of the `remote_name` and `title` columns. */
    private const MAX_NAME_LENGTH = 255;

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
        $maxTools = max(1, (int) config('code-talker.remote_mcp.max_tools', self::DEFAULT_MAX_TOOLS));

        try {
            $client = $this->clients->make($server);
            // A page carries at least one tool in any honest listing, so the
            // tool bound is also the page bound.
            $remoteTools = BoundedToolListing::fetchFrom($client, $maxTools, $maxTools)->values()->all();
        } catch (Throwable $e) {
            // Stored and shown to admins; the server URL can carry a token.
            return $this->failed($server, WebFetcher::redactText($e->getMessage()));
        } finally {
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // The listing is already in hand, or the sync already failed.
            }
        }

        try {
            [$stored, $unrepresentable, $changed] = $this->store($server, $remoteTools);
        } catch (Throwable $e) {
            // The transaction rolled back; put the model back as the database
            // has it, so recording the failure does not also write the
            // last_synced_at the failed write had set.
            $server->setRawAttributes($server->getRawOriginal(), true);

            // A database error's message carries the SQL and its bindings —
            // here, the remote server's own text. Log it; record the class.
            Log::warning('code-talker: storing a remote MCP tool catalog failed', [
                'server' => $server->slug,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->failed($server, 'Storing the synced catalog failed (' . $e::class . ').');
        }

        foreach ($changed as [$row, $previousHash]) {
            RemoteMcpToolDefinitionChanged::dispatch($server, $row, $previousHash);
        }

        return $this->report($server, $stored, $unrepresentable, null);
    }

    /**
     * @param array<int, RemoteTool> $remoteTools
     * @return array{0: int, 1: int, 2: array<int, array{0: AiMcpServerTool, 1: string}>}
     */
    private function store(AiMcpServer $server, array $remoteTools): array
    {
        return DB::transaction(function () use ($server, $remoteTools): array {
            $existing = $server->tools()->get();
            $existingByRemote = $existing->keyBy('remote_name');

            // Who held each exposed name before this sync. A grant names the
            // exposed name, so a name passing to a different remote tool
            // changes what the grant runs, exactly as a changed definition does.
            $previousOwner = $existing->whereNotNull('exposed_name')->keyBy('exposed_name');

            $entries = [];
            $seenExposed = [];

            foreach ($remoteTools as $remoteTool) {
                $attributes = $this->attributesFor($server, $remoteTool, $seenExposed);

                // Two overlong names cut to the same stored name: the first keeps it.
                $entries[$attributes['remote_name']] ??= $attributes;
            }

            // Remove what is no longer listed before writing anything, so a
            // name it held is free for the tool that takes it over.
            $server->tools()->whereNotIn('remote_name', array_keys($entries))->delete();

            // Likewise release any exposed name a kept row is about to give
            // up, so two kept rows can trade names without a unique violation.
            $releasing = $existing
                ->filter(fn (AiMcpServerTool $row): bool => isset($entries[$row->remote_name])
                    && $row->exposed_name !== null
                    && $row->exposed_name !== $entries[$row->remote_name]['exposed_name'])
                ->modelKeys();

            if ($releasing !== []) {
                AiMcpServerTool::query()->whereKey($releasing)->update(['exposed_name' => null]);
            }

            $changed = [];
            $unrepresentable = 0;
            $now = now();

            foreach ($entries as $remoteName => $attributes) {
                $attributes['synced_at'] = $now;

                if (!$attributes['representable']) {
                    $unrepresentable++;
                }

                $owner = $attributes['exposed_name'] !== null ? $previousOwner->get($attributes['exposed_name']) : null;
                $takenOver = $owner !== null && $owner->remote_name !== $remoteName;

                $row = $existingByRemote->get($remoteName);

                if ($row === null) {
                    $row = $server->tools()->create($attributes);

                    if ($takenOver) {
                        $changed[] = [$row, $owner->definition_hash];
                    }

                    continue;
                }

                $previousHash = $row->definition_hash;
                $row->fill($attributes)->save();

                if ($takenOver) {
                    $changed[] = [$row, $owner->definition_hash];
                } elseif ($previousHash !== $row->definition_hash) {
                    $changed[] = [$row, $previousHash];
                }
            }

            $server->forceFill([
                'last_synced_at' => $now,
                'last_sync_error' => null,
            ])->save();

            return [count($entries), $unrepresentable, $changed];
        });
    }

    /**
     * @param array<string, true> $seenExposed exposed names already taken on this server
     * @return array<string, mixed>
     */
    private function attributesFor(AiMcpServer $server, RemoteTool $remoteTool, array &$seenExposed): array
    {
        $remoteName = $remoteTool->name;
        $exposed = ExposedToolName::for($server->slug, $remoteName);
        $reason = RemoteToolSchema::unrepresentableReason($remoteTool->inputSchema);

        if (mb_strlen($remoteName) > self::MAX_NAME_LENGTH) {
            // Stored cut to fit, for visibility only: a call must send the
            // full name, so a tool stored under a shortened one is ungrantable.
            $reason = 'Its remote name is longer than ' . self::MAX_NAME_LENGTH . ' characters.';
            $remoteName = mb_substr($remoteName, 0, self::MAX_NAME_LENGTH);
            $exposed = null;
        } elseif (isset($seenExposed[$exposed])) {
            // Two remote names sanitized to the same exposed name. The first
            // keeps it; this one is stored for visibility but ungrantable.
            $reason = "Its exposed name {$exposed} collides with another tool on this server.";
            $exposed = null;
        } else {
            $seenExposed[$exposed] = true;
        }

        return [
            'remote_name' => $remoteName,
            'exposed_name' => $exposed,
            'title' => $remoteTool->title !== null ? mb_substr($remoteTool->title, 0, self::MAX_NAME_LENGTH) : null,
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
     * Record a failure on the server and report it. Recording is best effort:
     * a database that refused the catalog may refuse this too, and the
     * failure still reaches the caller through the report.
     *
     * @return array{server: string, stored: int, unrepresentable: int, error: string}
     */
    private function failed(AiMcpServer $server, string $error): array
    {
        try {
            $server->forceFill(['last_sync_error' => $error])->save();
        } catch (Throwable $e) {
            Log::warning('code-talker: recording a remote MCP sync failure failed', [
                'server' => $server->slug,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return $this->report($server, 0, 0, $error);
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

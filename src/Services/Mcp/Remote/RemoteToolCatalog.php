<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Remote;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Jvjvjv\CodeTalker\Models\AiMcpServerTool;

/**
 * Reads the stored catalog into registry handlers. Makes no network call:
 * building a tool list must work while every remote server is down, and it
 * also runs for admin listings where nobody should wait on a remote.
 */
class RemoteToolCatalog
{
    public function __construct(
        private readonly RemoteMcpClientFactory $clients,
    ) {
    }

    /**
     * @param array<int, string>|null $names exposed names to load; null loads every exposable tool
     * @return array<string, RemoteToolHandler> keyed by exposed name
     */
    public function handlers(?array $names): array
    {
        if (!config('code-talker.remote_mcp.enabled', true)) {
            return [];
        }

        if ($names !== null) {
            $names = array_values(array_filter($names, ExposedToolName::isRemote(...)));

            // Most systems grant no remote tools; they should cost no query.
            if ($names === []) {
                return [];
            }
        }

        try {
            $tools = AiMcpServerTool::query()
                ->with('server')
                ->where('representable', true)
                ->whereNotNull('exposed_name')
                ->when($names !== null, fn ($query) => $query->whereIn('exposed_name', $names))
                ->whereHas('server', fn ($query) => $query->where('enabled', true))
                ->orderBy('exposed_name')
                ->get();
        } catch (QueryException $e) {
            // A host that upgraded but has not yet migrated. Its turns should
            // still run on local tools rather than fail.
            Log::warning('code-talker: remote MCP catalog unavailable; remote tools skipped', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $sessions = [];
        $handlers = [];

        foreach ($tools as $tool) {
            $server = $tool->server;
            $sessions[$server->getKey()] ??= new RemoteServerSession($server, $this->clients);
            $handlers[$tool->exposed_name] = new RemoteToolHandler($tool, $sessions[$server->getKey()]);
        }

        return $handlers;
    }
}

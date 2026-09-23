<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Remote;

use Illuminate\Support\Facades\Log;
use Jvjvjv\CodeTalker\Models\AiMcpServerTool;
use Laravel\Mcp\Client\Schema\ToolResult;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Throwable;

/**
 * A single granted remote tool, as the registry sees it.
 *
 * handle() never throws. laravel/ai calls tools with no try/catch, so an
 * exception here would end the turn; instead every failure becomes an error
 * result the model can read and work around.
 *
 * Transport failures are logged in full but reported to the model generically:
 * their messages can carry the server URL, and the model may repeat anything
 * it is shown.
 */
class RemoteToolHandler
{
    public function __construct(
        private readonly AiMcpServerTool $tool,
        private readonly RemoteServerSession $session,
    ) {
    }

    public function name(): string
    {
        return (string) $this->tool->exposed_name;
    }

    public function description(): string
    {
        return (string) ($this->tool->description ?? $this->tool->title ?? $this->tool->remote_name);
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        $schema = $this->tool->input_schema;

        return is_array($schema) && $schema !== []
            ? $schema
            : ['type' => 'object', 'properties' => (object) []];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        $server = $this->session->server();

        try {
            $result = $this->session->call($this->tool->remote_name, $input);
        } catch (RemoteServerUnavailable) {
            return ['error' => "Remote tool {$this->name()} is unavailable: server {$server->slug} failed earlier in this turn."];
        } catch (JsonRpcException $e) {
            return ['error' => "Remote tool {$this->name()} rejected the call: {$e->getMessage()}"];
        } catch (Throwable $e) {
            Log::warning('code-talker: remote MCP tool call failed', [
                'server' => $server->slug,
                'tool' => $this->tool->remote_name,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return ['error' => "Remote tool {$this->name()} is unavailable: server {$server->slug} did not return a usable result."];
        }

        return $this->toArray($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(ToolResult $result): array
    {
        if ($result->isError) {
            $text = $result->text();

            if ($text === '' && $result->structuredContent !== null) {
                $text = (string) json_encode($result->structuredContent, JSON_UNESCAPED_UNICODE);
            }

            return ['error' => $text !== '' ? $text : "Remote tool {$this->name()} reported an error."];
        }

        if ($result->structuredContent !== null) {
            return $result->structuredContent;
        }

        return ['content' => $result->text()];
    }
}

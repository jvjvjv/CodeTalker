<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Remote;

use Illuminate\Support\Facades\Log;
use Jvjvjv\CodeTalker\Models\AiMcpServerTool;
use Jvjvjv\CodeTalker\Support\RedactedException;
use Laravel\Mcp\Client\Schema\ToolResult;
use Laravel\Mcp\Exceptions\JsonRpcException;
use RuntimeException;
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
 *
 * Results are capped at `remote_mcp.max_result_bytes` before they reach the
 * model: one runaway result would otherwise exceed the provider's request
 * limit, which fails the whole turn rather than the one call.
 */
class RemoteToolHandler
{
    private const DEFAULT_MAX_RESULT_BYTES = 100000;

    /** @var array<string, \Illuminate\JsonSchema\Types\Type>|null */
    private ?array $properties = null;

    private ?string $unrepresentableReason = null;

    private bool $converted = false;

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
     * The converted laravel/ai properties, converted once per handler — that
     * is, once per registry build — however often the tool list is asked for.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     *
     * @throws RuntimeException when the schema cannot be represented
     */
    public function properties(): array
    {
        $this->convert();

        if ($this->unrepresentableReason !== null) {
            throw new RuntimeException($this->unrepresentableReason);
        }

        return $this->properties;
    }

    public function unrepresentableReason(): ?string
    {
        $this->convert();

        return $this->unrepresentableReason;
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
            return ['error' => $this->capText("Remote tool {$this->name()} rejected the call: {$e->getMessage()}")];
        } catch (Throwable $e) {
            Log::warning('code-talker: remote MCP tool call failed', [
                'server' => $server->slug,
                'tool' => $this->tool->remote_name,
                ...RedactedException::diagnostic($e),
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
        // An empty structuredContent ({} on the wire) carries nothing; a server
        // that sends it beside text content means the text.
        $structured = $result->structuredContent === [] ? null : $result->structuredContent;

        if ($result->isError) {
            $text = $result->text();

            if ($text === '' && $structured !== null) {
                $text = (string) json_encode($structured, JSON_UNESCAPED_UNICODE);
            }

            return ['error' => $text !== '' ? $this->capText($text) : "Remote tool {$this->name()} reported an error."];
        }

        if ($structured !== null) {
            $encoded = (string) json_encode($structured, JSON_UNESCAPED_UNICODE);

            if (strlen($encoded) <= $this->maxResultBytes()) {
                return $structured;
            }

            return $this->truncated($encoded);
        }

        $text = $result->text();

        return strlen($text) <= $this->maxResultBytes()
            ? ['content' => $text]
            : $this->truncated($text);
    }

    /**
     * @return array{content: string, truncated: true, note: string}
     */
    private function truncated(string $text): array
    {
        $max = $this->maxResultBytes();

        return [
            'content' => mb_strcut($text, 0, $max, 'UTF-8'),
            'truncated' => true,
            'note' => "The result was " . strlen($text) . " bytes and has been cut to the first {$max}.",
        ];
    }

    private function capText(string $text): string
    {
        return strlen($text) <= $this->maxResultBytes() ? $text : mb_strcut($text, 0, $this->maxResultBytes(), 'UTF-8') . ' [truncated]';
    }

    private function maxResultBytes(): int
    {
        return max(1, (int) config('code-talker.remote_mcp.max_result_bytes', self::DEFAULT_MAX_RESULT_BYTES));
    }

    private function convert(): void
    {
        if ($this->converted) {
            return;
        }

        $this->converted = true;

        try {
            $this->properties = RemoteToolSchema::properties($this->inputSchema());
        } catch (RuntimeException $e) {
            $this->unrepresentableReason = $e->getMessage();
        }
    }
}

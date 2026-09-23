<?php

namespace Jvjvjv\CodeTalker\Tests\Support;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Enums\ProtocolVersion;

/**
 * A just-enough MCP server behind Http::fake(): it answers the initialize
 * handshake, pages `tools/list`, and routes `tools/call` to per-tool closures.
 * laravel/mcp's HTTP transport sends through the Http facade, so nothing
 * here opens a socket.
 */
class FakeMcpServer
{
    /** @var array<int, array{name: string, arguments: array<string, mixed>}> */
    public array $calls = [];

    /** Every request that reached this server, including refused ones. */
    public int $requests = 0;

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $toolPages;

    /** @var array<string, Closure(array<string, mixed>): array<string, mixed>> */
    private array $callHandlers = [];

    private ?string $downBecause = null;

    private ?Closure $override = null;

    /**
     * @param array<int, array<string, mixed>>|array<int, array<int, array<string, mixed>>> $tools
     *        a flat list of tool payloads, or a list of pages of them
     */
    public function __construct(public readonly string $url, array $tools = [])
    {
        $this->toolPages = $tools !== [] && array_is_list($tools[0] ?? null) ? $tools : [$tools];
    }

    public static function tool(string $name, array $properties = ['q' => ['type' => 'string']], ?string $description = null): array
    {
        return [
            'name' => $name,
            'description' => $description ?? "The {$name} tool.",
            'inputSchema' => ['type' => 'object', 'properties' => $properties],
        ];
    }

    /**
     * @param Closure(array<string, mixed>): array<string, mixed> $handler returns a tools/call result, or ['__error' => [code, message]]
     */
    public function onCall(string $name, Closure $handler): static
    {
        $this->callHandlers[$name] = $handler;

        return $this;
    }

    /**
     * @param array<int, array<string, mixed>>|array<int, array<int, array<string, mixed>>> $tools
     */
    public function setTools(array $tools): static
    {
        $this->toolPages = $tools !== [] && array_is_list($tools[0] ?? null) ? $tools : [$tools];

        return $this;
    }

    /**
     * Refuse every request from now on. Changing this instance's behavior,
     * rather than calling Http::fake() again for the same URL, matters: the
     * first matching stub wins, so a second fake would never be reached.
     */
    public function down(string $message = 'Connection refused'): static
    {
        $this->downBecause = $message;

        return $this;
    }

    /**
     * @param Closure(Request): mixed $override answers every request instead of the fake protocol
     */
    public function respondWith(Closure $override): static
    {
        $this->override = $override;

        return $this;
    }

    public function fake(): static
    {
        Http::fake([$this->url => fn (Request $request) => $this->respond($request)]);

        return $this;
    }

    private function respond(Request $request): mixed
    {
        $this->requests++;

        if ($this->downBecause !== null) {
            return Http::failedConnection($this->downBecause);
        }

        if ($this->override !== null) {
            return ($this->override)($request);
        }

        if ($request->method() !== 'POST') {
            return Http::response('', 200);
        }

        $message = json_decode($request->body(), true);

        if (!isset($message['id'])) {
            return Http::response('', 202);
        }

        $method = $message['method'];
        $params = $message['params'] ?? [];

        $result = match ($method) {
            'initialize' => [
                'protocolVersion' => ProtocolVersion::LATEST->value,
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => 'fake', 'version' => '1.0.0'],
            ],
            'tools/list' => $this->listPage($params['cursor'] ?? null),
            'tools/call' => $this->call($params['name'], (array) ($params['arguments'] ?? [])),
            default => ['__error' => [-32601, "Unknown method {$method}"]],
        };

        if (isset($result['__error'])) {
            [$code, $text] = $result['__error'];

            return Http::response(['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => $code, 'message' => $text]]);
        }

        if (isset($result['__raw'])) {
            return Http::response(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result['__raw']]);
        }

        return Http::response(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result]);
    }

    private function listPage(?string $cursor): array
    {
        $index = $cursor === null ? 0 : (int) $cursor;
        $page = ['tools' => $this->toolPages[$index] ?? []];

        if (isset($this->toolPages[$index + 1])) {
            $page['nextCursor'] = (string) ($index + 1);
        }

        return $page;
    }

    private function call(string $name, array $arguments): array
    {
        $this->calls[] = ['name' => $name, 'arguments' => $arguments];

        $handler = $this->callHandlers[$name] ?? static fn (array $args): array => [
            'content' => [['type' => 'text', 'text' => "{$name} ran"]],
        ];

        return $handler($arguments);
    }
}

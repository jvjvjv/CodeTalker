<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Remote;

use Jvjvjv\CodeTalker\Models\AiMcpServer;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Laravel\Mcp\Client\Schema\ToolResult;
use Laravel\Mcp\Exceptions\JsonRpcException;
use RuntimeException;
use Throwable;

/**
 * One remote server's connection for the life of a tool registry — in
 * practice, one turn.
 *
 * Nothing touches the network until the first call. After a transport or
 * protocol failure the session trips, and every later call fails at once:
 * without that, a dead server costs a full timeout on each of the agent
 * loop's steps. A JSON-RPC error to a tool call is the server answering, so
 * it never trips; one to the `initialize` handshake means no tool on the
 * server can be called, so it trips like a transport failure.
 */
class RemoteServerSession
{
    private ?Client $client = null;

    private ?string $trippedBecause = null;

    public function __construct(
        private readonly AiMcpServer $server,
        private readonly RemoteMcpClientFactory $factory,
    ) {
    }

    public function server(): AiMcpServer
    {
        return $this->server;
    }

    public function tripped(): bool
    {
        return $this->trippedBecause !== null;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws RemoteServerUnavailable when the breaker has tripped
     * @throws JsonRpcException when the server rejects the call
     * @throws Throwable on any transport or protocol failure, or a rejected handshake (which trip the breaker)
     */
    public function call(string $remoteName, array $arguments): ToolResult
    {
        if ($this->trippedBecause !== null) {
            throw new RemoteServerUnavailable($this->trippedBecause);
        }

        try {
            return $this->attempt($remoteName, $arguments);
        } catch (AuthorizationRequiredException $e) {
            if (!$this->factory->usesRefreshableToken($this->server)) {
                $this->trip($e);

                throw $e;
            }

            // A cached client-credentials token may have been revoked before
            // it expired. Fetch a fresh one and retry exactly once.
            $this->factory->forgetToken($this->server);
            $this->client = null;

            try {
                return $this->attempt($remoteName, $arguments);
            } catch (JsonRpcException $retryError) {
                throw $this->handshakeFailure($retryError) ?? $retryError;
            } catch (Throwable $retryError) {
                $this->trip($retryError);

                throw $retryError;
            }
        } catch (JsonRpcException $e) {
            throw $this->handshakeFailure($e) ?? $e;
        } catch (Throwable $e) {
            $this->trip($e);

            throw $e;
        }
    }

    public function disconnect(): void
    {
        try {
            $this->client?->disconnect();
        } catch (Throwable) {
            // Best effort: the session is being discarded either way.
        }

        $this->client = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function attempt(string $remoteName, array $arguments): ToolResult
    {
        $this->client ??= $this->factory->make($this->server);

        return $this->client->callTool($remoteName, $arguments);
    }

    /**
     * A JSON-RPC error raised while the client is not connected came from the
     * handshake (laravel/mcp disconnects when `initialize` fails), not from
     * the tool. Trip, and report it as a server failure rather than as the
     * tool rejecting the model's arguments.
     */
    private function handshakeFailure(JsonRpcException $e): ?RuntimeException
    {
        if ($this->client?->connected()) {
            return null;
        }

        $this->trip($e);

        return new RuntimeException('MCP session handshake rejected: ' . $e->getMessage(), 0, $e);
    }

    private function trip(Throwable $cause): void
    {
        $this->trippedBecause = $cause->getMessage();
        $this->client = null;
    }
}

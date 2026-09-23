<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Remote;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Models\AiMcpServer;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\OAuth\TokenSet;
use Laravel\Mcp\Exceptions\ClientException;
use Laravel\Mcp\WebClient;

/**
 * Builds a laravel/mcp client for a stored server, with its auth and timeout
 * applied.
 *
 * Supported `auth` shapes:
 *   {type: none}
 *   {type: bearer, token}
 *   {type: headers, headers: {name: value}}
 *   {type: client_credentials, client_id, client_secret?, scope?, token_endpoint?}
 *
 * laravel/mcp persists no tokens, so a client-credentials token is cached
 * here until shortly before it expires. With no `token_endpoint`, the token
 * endpoint is found by laravel/mcp's OAuth discovery against the server URL.
 */
class RemoteMcpClientFactory
{
    /** Seconds shaved off a token's lifetime so it is never used at the edge. */
    private const TOKEN_EXPIRY_MARGIN = 30;

    /** Lifetime assumed for a token whose response omits `expires_in`. */
    private const TOKEN_DEFAULT_TTL = 300;

    public function make(AiMcpServer $server): WebClient
    {
        if (($server->transport ?? AiMcpServer::TRANSPORT_HTTP) !== AiMcpServer::TRANSPORT_HTTP) {
            throw new ClientException("MCP server [{$server->slug}] uses an unsupported transport.");
        }

        $client = Client::web($server->url)->withTimeout($server->effectiveTimeoutSeconds());
        $auth = (array) ($server->auth ?? []);

        return match ($auth['type'] ?? 'none') {
            'bearer' => $client->withToken((string) ($auth['token'] ?? '')),
            'headers' => $client->withHeaders(array_map('strval', (array) ($auth['headers'] ?? []))),
            // Resolved per request, so a token forgotten after a 401 is
            // re-fetched on the retry without rebuilding the client.
            'client_credentials' => $client->withToken(fn (): string => $this->clientCredentialsToken($server)),
            default => $client,
        };
    }

    public function usesRefreshableToken(AiMcpServer $server): bool
    {
        return (($server->auth ?? [])['type'] ?? null) === 'client_credentials';
    }

    public function clientCredentialsToken(AiMcpServer $server): string
    {
        $key = $this->tokenCacheKey($server);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $tokens = $this->requestClientCredentialsToken($server);

        if ($tokens->accessToken === '') {
            throw new ClientException("Token endpoint for MCP server [{$server->slug}] returned no access token.");
        }

        $ttl = $tokens->expiresAt !== null
            ? $tokens->expiresAt - time() - self::TOKEN_EXPIRY_MARGIN
            : self::TOKEN_DEFAULT_TTL;

        if ($ttl > 0) {
            Cache::put($key, $tokens->accessToken, $ttl);
        }

        return $tokens->accessToken;
    }

    public function forgetToken(AiMcpServer $server): void
    {
        Cache::forget($this->tokenCacheKey($server));
    }

    protected function requestClientCredentialsToken(AiMcpServer $server): TokenSet
    {
        $auth = (array) ($server->auth ?? []);
        $clientId = isset($auth['client_id']) ? (string) $auth['client_id'] : null;
        $clientSecret = isset($auth['client_secret']) ? (string) $auth['client_secret'] : null;
        $scope = isset($auth['scope']) ? (string) $auth['scope'] : null;

        if (filled($auth['token_endpoint'] ?? null)) {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout($server->effectiveTimeoutSeconds())
                ->post((string) $auth['token_endpoint'], array_filter([
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => $scope,
                ], static fn (?string $value): bool => $value !== null && $value !== ''));

            if (!$response->successful() || !is_array($response->json())) {
                throw new ClientException("Token request for MCP server [{$server->slug}] failed with HTTP {$response->status()}.");
            }

            return TokenSet::fromResponse($response->json());
        }

        return Client::web($server->url)
            ->withOAuth($clientId, $clientSecret, $scope)
            ->oAuthClient()
            ->clientCredentials();
    }

    /**
     * Keyed on the auth block too, so rotating a secret stops the old token
     * from being served.
     */
    private function tokenCacheKey(AiMcpServer $server): string
    {
        return 'code-talker:remote-mcp-token:' . $server->getKey() . ':' . sha1((string) json_encode($server->auth));
    }
}

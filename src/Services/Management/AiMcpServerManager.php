<?php

namespace Jvjvjv\CodeTalker\Services\Management;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Jvjvjv\CodeTalker\Models\AiMcpServer;
use Jvjvjv\CodeTalker\Models\AiMcpServerTool;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\Mcp\Remote\ExposedToolName;
use Jvjvjv\CodeTalker\Services\Mcp\Remote\RemoteToolCatalogSync;

/**
 * Every write operation on a remote MCP server record, plus catalog syncs.
 *
 * The slug is immutable after creation: it is the prefix of every exposed
 * tool name, so changing it would orphan every grant made against the server.
 * Create and update sync the catalog after the write; a failed sync is
 * reported and recorded on the server, never thrown, so a server that is down
 * right now can still be saved.
 */
class AiMcpServerManager
{
    public const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,22}[a-z0-9])?$/';

    public const AUTH_TYPES = ['none', 'bearer', 'headers', 'client_credentials'];

    public function __construct(
        private readonly RemoteToolCatalogSync $catalogSync,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function createRules(array $data = []): array
    {
        return [
            'slug' => ['required', 'string', 'regex:' . self::SLUG_PATTERN, Rule::unique('ai_mcp_servers', 'slug')],
            ...static::sharedRules(),
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    /**
     * No `slug` rule: a submitted slug is discarded, not validated.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function updateRules(array $data = []): array
    {
        return [
            ...static::sharedRules(),
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'url' => ['sometimes', 'required', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function sharedRules(): array
    {
        return [
            // stdio is deliberately absent: a command line stored in an
            // admin-editable table would let the admin UI run commands.
            'transport' => ['nullable', 'string', Rule::in([AiMcpServer::TRANSPORT_HTTP])],
            'auth' => ['nullable', static::authRule()],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:300'],
            'enabled' => ['boolean'],
        ];
    }

    /**
     * Validate the decoded shape of `auth`, which may arrive as an array or a
     * JSON string.
     */
    private static function authRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $auth = is_string($value) ? json_decode($value, true) : $value;

            if (!is_array($auth)) {
                $fail('The :attribute must be an object.');

                return;
            }

            $type = $auth['type'] ?? null;

            if (!in_array($type, self::AUTH_TYPES, true)) {
                $fail('The :attribute type must be one of: ' . implode(', ', self::AUTH_TYPES) . '.');

                return;
            }

            $isNonEmptyString = static fn (mixed $v): bool => is_string($v) && $v !== '';

            if ($type === 'bearer' && !$isNonEmptyString($auth['token'] ?? null)) {
                $fail('The :attribute token is required for bearer auth.');
            }

            if ($type === 'headers') {
                $headers = $auth['headers'] ?? null;

                if (!is_array($headers) || $headers === []
                    || array_filter($headers, static fn (mixed $h): bool => !is_string($h) && !is_numeric($h)) !== []) {
                    $fail('The :attribute headers must be a non-empty map of header names to strings.');
                }
            }

            if ($type === 'client_credentials' && !$isNonEmptyString($auth['client_id'] ?? null)) {
                $fail('The :attribute client_id is required for client_credentials auth.');
            }

            if ($type === 'client_credentials'
                && isset($auth['token_endpoint'])
                && filter_var($auth['token_endpoint'], FILTER_VALIDATE_URL) === false) {
                $fail('The :attribute token_endpoint must be a URL.');
            }
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array{server: AiMcpServer, sync: array{server: string, stored: int, unrepresentable: int, error: ?string}}
     */
    public function create(array $data): array
    {
        $data = Validator::make($data, static::createRules($data))->validate();
        $this->decodeAuth($data);
        $data['transport'] ??= AiMcpServer::TRANSPORT_HTTP;

        $server = AiMcpServer::create($data);

        return ['server' => $server, 'sync' => $this->sync($server)];
    }

    /**
     * An omitted `auth` keeps the stored credentials, so an edit form never
     * has to round-trip a secret it should not be displaying.
     *
     * @param array<string, mixed> $data
     * @return array{server: AiMcpServer, sync: array{server: string, stored: int, unrepresentable: int, error: ?string}}
     */
    public function update(AiMcpServer $server, array $data): array
    {
        unset($data['slug']);

        $data = Validator::make($data, static::updateRules($data))->validate();
        $this->decodeAuth($data);

        $server->update($data);

        return ['server' => $server, 'sync' => $this->sync($server)];
    }

    /**
     * @return array{server: string, stored: int, unrepresentable: int, error: ?string}
     */
    public function sync(AiMcpServer $server): array
    {
        return $this->catalogSync->sync($server);
    }

    /**
     * @return array<int, array{server: string, stored: int, unrepresentable: int, error: ?string}>
     */
    public function syncAll(): array
    {
        return AiMcpServer::query()
            ->where('enabled', true)
            ->orderBy('slug')
            ->get()
            ->map(fn (AiMcpServer $server): array => $this->sync($server))
            ->all();
    }

    /**
     * Soft-deletes the server, which withdraws its tools everywhere. Grants
     * naming them are left in place — an unknown name in `allowed_tools` is
     * ignored — and counted, so the caller can say how many systems lost one.
     *
     * @return int the number of AI systems that had granted one of its tools
     */
    public function delete(AiMcpServer $server): int
    {
        $prefix = $server->slug . ExposedToolName::SEPARATOR;

        $referencing = AiSystem::query()
            ->whereNotNull('allowed_tools')
            ->pluck('allowed_tools')
            ->filter(static fn (mixed $tools): bool => is_array($tools) && array_filter(
                $tools,
                static fn (mixed $name): bool => is_string($name) && str_starts_with($name, $prefix),
            ) !== [])
            ->count();

        $server->delete();

        return $referencing;
    }

    /**
     * Servers with their sync health and catalog, for an admin screen.
     * `auth` is never included.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return AiMcpServer::query()
            ->with(['tools' => fn ($query) => $query->orderBy('remote_name')])
            ->orderBy('name')
            ->get()
            ->map(static fn (AiMcpServer $server): array => [
                'id' => $server->id,
                'slug' => $server->slug,
                'name' => $server->name,
                'transport' => $server->transport,
                'url' => $server->url,
                'auth_type' => ($server->auth ?? [])['type'] ?? 'none',
                'timeout_seconds' => $server->timeout_seconds,
                'enabled' => $server->enabled,
                'last_synced_at' => $server->last_synced_at,
                'last_sync_error' => $server->last_sync_error,
                'tools' => $server->tools->map(static fn (AiMcpServerTool $tool): array => [
                    'exposed_name' => $tool->exposed_name,
                    'remote_name' => $tool->remote_name,
                    'title' => $tool->title,
                    'description' => $tool->description,
                    'available' => $tool->representable,
                    'unavailable_reason' => $tool->unrepresentable_reason,
                    'synced_at' => $tool->synced_at,
                ])->all(),
            ])
            ->all();
    }

    /**
     * `auth` is cast to an encrypted array, so a JSON string has to be decoded
     * before the write or the cast stores a string of a string.
     *
     * @param array<string, mixed> $data
     */
    private function decodeAuth(array &$data): void
    {
        if (isset($data['auth']) && is_string($data['auth'])) {
            $data['auth'] = json_decode($data['auth'], true);
        }
    }
}

<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An external MCP server whose tools an AiSystem can be granted.
 *
 * The server's tool list is never fetched during a turn — it is synced into
 * AiMcpServerTool rows, and turns read those. `auth` is hidden so a server
 * serialized into an admin payload never carries its credentials.
 */
class AiMcpServer extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const TRANSPORT_HTTP = 'http';

    protected $fillable = [
        'slug',
        'name',
        'transport',
        'url',
        'auth',
        'timeout_seconds',
        'enabled',
        'last_synced_at',
        'last_sync_error',
    ];

    protected $hidden = [
        'auth',
    ];

    protected function casts(): array
    {
        return [
            'auth' => 'encrypted:array',
            'timeout_seconds' => 'integer',
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function tools(): HasMany
    {
        return $this->hasMany(AiMcpServerTool::class, 'ai_mcp_server_id');
    }

    /**
     * The timeout, in seconds, bounding the handshake and each call. A server
     * may ask for less than the configured cap but never more.
     */
    public function effectiveTimeoutSeconds(): float
    {
        $default = (int) config('code-talker.remote_mcp.default_timeout_seconds', 10);
        $max = (int) config('code-talker.remote_mcp.max_timeout_seconds', 30);

        return (float) max(1, min($this->timeout_seconds ?? $default, $max));
    }
}

<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tool from a remote MCP server's synced catalog.
 *
 * `exposed_name` is the tool's name everywhere in this package: on the wire,
 * in the registry, and in `allowed_tools`. A tool that is not `representable`
 * is kept for visibility but never offered to a model.
 */
class AiMcpServerTool extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_mcp_server_id',
        'remote_name',
        'exposed_name',
        'title',
        'description',
        'input_schema',
        'annotations',
        'representable',
        'unrepresentable_reason',
        'definition_hash',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'annotations' => 'array',
            'representable' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(AiMcpServer::class, 'ai_mcp_server_id');
    }
}

<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AiProviderExchange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'provider',
        'endpoint',
        'method',
        'streaming',
        'http_status',
        'request_body',
        'raw_response',
        'model',
        'duration_ms',
        'ai_system_id',
        'ai_conversation_id',
        'ai_llm_message_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'streaming' => 'boolean',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $exchange): void {
            if ($exchange->created_at === null) {
                $exchange->created_at = Carbon::now();
            }
        });
    }
}

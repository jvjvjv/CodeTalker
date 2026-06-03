<?php

namespace Jvjvjv\CodeTalker\Models;

use Jvjvjv\CodeTalker\Enums\AiInteractionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiInteractionLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ai_system_id',
        'ai_conversation_id',
        'ai_chat_bot_id',
        'user_id',
        'feature',
        'input_tokens',
        'output_tokens',
        'model',
        'input_token_price_snapshot',
        'output_token_price_snapshot',
        'provider_metadata',
        'duration_ms',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'input_token_price_snapshot' => 'decimal:8',
            'output_token_price_snapshot' => 'decimal:8',
            'provider_metadata' => 'array',
            'duration_ms' => 'integer',
            'status' => AiInteractionStatus::class,
        ];
    }

    public function aiSystem(): BelongsTo
    {
        return $this->belongsTo(AiSystem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('code-talker.user_model'));
    }

    public function aiChatBot(): BelongsTo
    {
        return $this->belongsTo(AiChatBot::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}

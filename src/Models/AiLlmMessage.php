<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AiLlmMessage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'ai_conversation_id',
        'direction',
        'turn_number',
        'request_data',
        'response_data',
        'raw_response',
        'duration_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'request_data' => 'array',
            'response_data' => 'array',
            'raw_response' => 'array',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $message): void {
            if ($message->created_at === null) {
                $message->created_at = Carbon::now();
            }
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class);
    }
}

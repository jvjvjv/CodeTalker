<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AiConversationMessage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            if ($message->created_at === null) {
                $message->created_at = Carbon::now();
            }
        });
    }

    protected $fillable = [
        'ai_conversation_id',
        'role',
        'content',
        'reasoning_content',
        'blocks',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}

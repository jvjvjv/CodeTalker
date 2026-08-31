<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Jvjvjv\CodeTalker\Enums\AiTurnRunStatus;

/**
 * One attempt at a conversation turn, run detached from any HTTP connection.
 *
 * The run is what a browser reattaches to after a reload: it holds the prompt
 * the job replays, the status a reader stops on, and the two timestamps that
 * decide whether anyone still wants the answer.
 */
class AiTurnRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_conversation_id',
        'public_id',
        'status',
        'prompt',
        'last_polled_at',
        'cancel_requested_at',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            if (blank($run->public_id)) {
                $run->public_id = (string) Str::ulid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => AiTurnRunStatus::class,
            'last_polled_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AiTurnEvent::class, 'ai_turn_run_id')->orderBy('sequence');
    }
}

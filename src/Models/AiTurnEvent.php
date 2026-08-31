<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event a detached turn produced, in the shape continueConversation()
 * yields it. Framing is still the encoder's job, so what is stored here is a
 * structured event, never a wire frame.
 */
class AiTurnEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if ($event->created_at === null) {
                $event->created_at = Carbon::now();
            }
        });
    }

    protected $fillable = [
        'ai_turn_run_id',
        'sequence',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiTurnRun::class, 'ai_turn_run_id');
    }
}

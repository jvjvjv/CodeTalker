<?php

namespace Jvjvjv\CodeTalker\Models;

use Jvjvjv\CodeTalker\Enums\AiConversationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class AiConversation extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $appends = [
        'is_stale',
    ];

    protected $fillable = [
        'public_id',
        'uuid',
        'chat_hash',
        'user_id',
        'ai_system_id',
        'ai_persona_id',
        'ai_operator_id',
        'feature',
        'title',
        'visitor_name',
        'visitor_email',
        'status',
        'context',
        'usage_input_tokens',
        'usage_output_tokens',
        'usage_total_tokens',
        'usage_cost_usd',
        'usage_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'status' => AiConversationStatus::class,
            'last_message_at' => 'datetime',
            'usage_input_tokens' => 'integer',
            'usage_output_tokens' => 'integer',
            'usage_total_tokens' => 'integer',
            'usage_cost_usd' => 'decimal:6',
            'usage_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $conversation): void {
            if (blank($conversation->public_id)) {
                $conversation->public_id = (string) Str::ulid();
            }

            if (blank($conversation->uuid)) {
                $conversation->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('code-talker.user_model'));
    }

    public function aiSystem(): BelongsTo
    {
        return $this->belongsTo(AiSystem::class);
    }

    public function aiPersona(): BelongsTo
    {
        return $this->belongsTo(AiPersona::class);
    }

    public function aiOperator(): BelongsTo
    {
        return $this->belongsTo(AiOperator::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiConversationMessage::class)->orderBy('created_at');
    }

    public function scopeWithLastMessageAt(Builder $query): Builder
    {
        return $query->withMax([
            'messages as last_message_at' => fn (Builder $messageQuery) => $messageQuery->where('role', '!=', 'system'),
        ], 'created_at');
    }

    public function scopeOrderByLastMessageAtDesc(Builder $query): Builder
    {
        return $query
            ->withLastMessageAt()
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at');
    }

    public function getLastMessageAtAttribute(): ?\Illuminate\Support\Carbon
    {
        if (array_key_exists('last_message_at', $this->attributes)) {
            $lastMessageAt = $this->attributes['last_message_at'];

            return $lastMessageAt ? $this->asDateTime($lastMessageAt) : null;
        }

        $lastMessageTimestamp = $this->messages()
            ->reorder()
            ->where('role', '!=', 'system')
            ->latest('created_at')
            ->value('created_at');

        return $lastMessageTimestamp ? $this->asDateTime($lastMessageTimestamp) : null;
    }

    public function interactionLogs(): HasMany
    {
        return $this->hasMany(AiInteractionLog::class);
    }

    public function getIsStaleAttribute(): bool
    {
        $lastActivity = $this->last_message_at ?? $this->created_at;

        return $lastActivity !== null && $lastActivity->lt(now()->subDays(7));
    }

    /**
     * Generate a deterministic MD5-based URL-safe hash for this conversation.
     * Stable across renames, feature changes, and timestamps.
     */
    public function generateChatHash(): string
    {
        $this->chat_hash = md5($this->uuid);
        $this->save();

        return $this->chat_hash;
    }

    public static function findByChatHash(string $hash): ?self
    {
        return static::query()->where('chat_hash', $hash)->first();
    }

    public static function findByChatHashOrUuid(string $identifier): ?self
    {
        return static::query()->where('chat_hash', $identifier)->first()
            ?? static::query()->where('uuid', $identifier)->first();
    }
}

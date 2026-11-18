<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFeatureMemory extends Model
{
    use HasFactory;

    protected $fillable = [
        'feature',
        'category',
        'key',
        'content',
        'confidence',
        'source_conversation_id',
        'user_id',
        'visitor_email',
        'last_reinforced_at',
        'times_reinforced',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'times_reinforced' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
            'last_reinforced_at' => 'datetime',
        ];
    }

    public function sourceConversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'source_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('code-talker.user_model'));
    }

    /**
     * Whether a user id or a visitor email is present to scope memories by.
     * Blank values count as absent.
     */
    public static function hasIdentity(string|int|null $userId, ?string $visitorEmail): bool
    {
        return ($userId !== null && $userId !== '') || ($visitorEmail !== null && $visitorEmail !== '');
    }

    /** @param Builder<self> $query */
    public function scopeForUser(Builder $query, string|int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * A visitor email is whatever the visitor typed, so it must never reach a
     * row that belongs to a user — including legacy rows that carry both.
     *
     * @param Builder<self> $query
     */
    public function scopeForVisitor(Builder $query, string $email): Builder
    {
        return $query->where('visitor_email', $email)->whereNull('user_id');
    }

    /**
     * Scope to exactly one identity: the user when there is one, else the
     * visitor. Throws rather than return an unscoped query, which would read
     * or modify every memory for the feature.
     *
     * @param Builder<self> $query
     *
     * @throws \InvalidArgumentException when neither identity is given
     */
    public function scopeForIdentity(Builder $query, string|int|null $userId, ?string $visitorEmail): Builder
    {
        if ($userId !== null && $userId !== '') {
            return $query->forUser($userId);
        }

        if ($visitorEmail !== null && $visitorEmail !== '') {
            return $query->forVisitor($visitorEmail);
        }

        throw new \InvalidArgumentException('A memory query requires a user id or a visitor email.');
    }

    /** @param Builder<self> $query */
    public function scopeForFeature(Builder $query, string $feature): Builder
    {
        return $query->where('feature', $feature);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @param Builder<self> $query */
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}

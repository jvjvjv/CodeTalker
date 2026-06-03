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

    /** @param Builder<self> $query */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /** @param Builder<self> $query */
    public function scopeForVisitor(Builder $query, string $email): Builder
    {
        return $query->where('visitor_email', $email);
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

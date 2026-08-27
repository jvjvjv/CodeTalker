<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiOperator extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'ai_system_id',
        'name',
        'slug',
        'description',
        'prompt_template',
        'allowed_tools',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allowed_tools' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param Builder<self> $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function aiSystem(): BelongsTo
    {
        return $this->belongsTo(AiSystem::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    public function featureKey(): string
    {
        return 'operator:' . $this->slug;
    }
}

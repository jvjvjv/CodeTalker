<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiSystemPrompt extends Model
{
    protected $fillable = ['title', 'description', 'content'];

    /** @param Builder<AiSystemPrompt> $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN id BETWEEN 1 AND 5 THEN id ELSE 999 END, title');
    }

    public function aiSystems(): HasMany
    {
        return $this->hasMany(AiSystem::class, 'system_prompt_id');
    }
}

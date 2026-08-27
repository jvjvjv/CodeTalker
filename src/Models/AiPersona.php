<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiPersona extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const ACCESS_PATH_CHAT = 'chat';

    public const ACCESS_PATH_ROOT = 'root';

    protected $fillable = [
        'ai_system_id',
        'context_length',
        'temperature',
        'name',
        'slug',
        'access_path',
        'description',
        'prompt_template',
        'is_active',
        'require_visitor_identity',
        'tools_enabled',
    ];

    protected function casts(): array
    {
        return [
            'context_length' => 'integer',
            'temperature' => 'decimal:2',
            'is_active' => 'boolean',
            'require_visitor_identity' => 'boolean',
            'tools_enabled' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Slugs reserved by the framework and common Laravel routes.
     * Override or extend this in your host app by merging with config('code-talker.reserved_slugs').
     *
     * @return array<int, string>
     */
    public static function reservedRootSlugs(): array
    {
        $defaults = [
            'about',
            'admin',
            'api',
            'chat',
            'chats',
            'forgot-password',
            'login',
            'logout',
            'profile',
            'register',
            'reset-password',
            'sanctum',
            'two-factor-challenge',
            'user',
        ];

        return array_merge($defaults, config('code-talker.reserved_slugs', []));
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

    public function interactionLogs(): HasMany
    {
        return $this->hasMany(AiInteractionLog::class);
    }

    public function featureKey(): string
    {
        return 'persona:' . $this->slug;
    }

    public function resolvedTemperature(): ?float
    {
        if ($this->temperature !== null) {
            return (float) $this->temperature;
        }

        if ($this->aiSystem?->temperature !== null) {
            return (float) $this->aiSystem->temperature;
        }

        return null;
    }

    public function resolvedContextLength(): ?int
    {
        return $this->context_length ?? $this->aiSystem?->context_length;
    }

    public function usesRootAccessPath(): bool
    {
        return $this->access_path === self::ACCESS_PATH_ROOT;
    }

    public function usesChatAccessPath(): bool
    {
        return $this->access_path !== self::ACCESS_PATH_ROOT;
    }

    public function publicPath(): string
    {
        return $this->usesRootAccessPath()
            ? '/' . $this->slug
            : '/chat/' . $this->slug;
    }
}

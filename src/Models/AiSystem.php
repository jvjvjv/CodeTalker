<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jvjvjv\CodeTalker\Enums\AiSystemRole;
use Jvjvjv\CodeTalker\Enums\ThinkingLevel;

class AiSystem extends Model
{
    use HasFactory, SoftDeletes;

    // duplicated_at is deliberately excluded from $fillable — it must only be
    // set by AiSystemManager::duplicate(), never accepted from a request.
    protected $fillable = [
        'name',
        'provider',
        'role',
        'api_key',
        'model',
        'model_capabilities',
        'base_url',
        'api_version',
        'max_tokens',
        'context_length',
        'temperature',
        'is_active',
        'config',
        'system_prompt_id',
        'credentials',
        'auth_type',
        'endpoint_type',
        'stream_protocol',
        'system_prompt_mode',
        'supports_tools',
        'allowed_tools',
        'web_tool_policy',
        'supports_json_mode',
        // @deprecated Superseded by `thinking_level`. Still written alongside
        // it by AiSystemManager so a rollback reads the right value.
        'enable_thinking',
        'thinking_level',
        'is_local_endpoint',
        // @deprecated Slated for removal — a host-configured pricing
        // override is redundant with the provider-level pricing config
        // (code-talker.providers.*.pricing) ConversationUsageService already
        // falls back to.
        'pricing_profile',
    ];

    // Encrypted at rest, and kept out of toArray()/toJson() so a system passed
    // to a response or an admin payload never carries them. Read them as
    // attributes; replicate() copies them regardless.
    protected $hidden = [
        'api_key',
        'credentials',
        'web_tool_policy',
    ];

    // Mirrors the column default, so a system built in memory resolves the
    // same way as one read back from the database.
    protected $attributes = [
        'role' => 'chat',
    ];

    protected function casts(): array
    {
        return [
            'role' => AiSystemRole::class,
            'api_key' => 'encrypted',
            'config' => 'array',
            'credentials' => 'encrypted:array',
            'model_capabilities' => 'array',
            /** @deprecated See the `$fillable` entry above. */
            'pricing_profile' => 'array',
            'is_active' => 'boolean',
            'supports_tools' => 'boolean',
            'allowed_tools' => 'array',
            'web_tool_policy' => 'encrypted:array',
            'supports_json_mode' => 'boolean',
            /** @deprecated See the `$fillable` entry above. */
            'enable_thinking' => 'boolean',
            'thinking_level' => ThinkingLevel::class,
            'is_local_endpoint' => 'boolean',
            'temperature' => 'decimal:2',
            'max_tokens' => 'integer',
            'context_length' => 'integer',
            'duplicated_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRole($query, AiSystemRole $role)
    {
        return $query->where('role', $role->value);
    }

    /**
     * The level this system thinks at. Falls back to the deprecated
     * `enable_thinking` flag when no level is stored or the column is not
     * loaded (a partial select, or a host that has not run the migration),
     * so an unmigrated system keeps thinking as it did.
     */
    public function thinkingLevel(): ?ThinkingLevel
    {
        $level = $this->getAttribute('thinking_level');

        if ($level instanceof ThinkingLevel) {
            return $level;
        }

        return ThinkingLevel::fromLegacyFlag($this->getAttribute('enable_thinking'));
    }

    public static function defaultForFeature(string $feature): ?self
    {
        $default = AiSystemFeatureDefault::where('feature', $feature)->first();

        return $default?->aiSystem;
    }

    public function systemPrompt(): BelongsTo
    {
        return $this->belongsTo(AiSystemPrompt::class, 'system_prompt_id');
    }

    public function featureDefaults(): HasMany
    {
        return $this->hasMany(AiSystemFeatureDefault::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    public function personas(): HasMany
    {
        return $this->hasMany(AiPersona::class);
    }

    public function operators(): HasMany
    {
        return $this->hasMany(AiOperator::class);
    }

    public function interactionLogs(): HasMany
    {
        return $this->hasMany(AiInteractionLog::class);
    }
}

<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiSystem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'provider',
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
        'supports_json_mode',
        'enable_thinking',
        'is_local_endpoint',
        'pricing_profile',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'config' => 'array',
            'credentials' => 'encrypted:array',
            'model_capabilities' => 'array',
            'pricing_profile' => 'array',
            'is_active' => 'boolean',
            'supports_tools' => 'boolean',
            'allowed_tools' => 'array',
            'supports_json_mode' => 'boolean',
            'enable_thinking' => 'boolean',
            'is_local_endpoint' => 'boolean',
            'temperature' => 'decimal:2',
            'max_tokens' => 'integer',
            'context_length' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
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

    public function chatBots(): HasMany
    {
        return $this->hasMany(AiChatBot::class);
    }

    public function interactionLogs(): HasMany
    {
        return $this->hasMany(AiInteractionLog::class);
    }
}

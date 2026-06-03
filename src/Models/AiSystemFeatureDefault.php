<?php

namespace Jvjvjv\CodeTalker\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSystemFeatureDefault extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_system_id',
        'feature',
    ];

    public function aiSystem(): BelongsTo
    {
        return $this->belongsTo(AiSystem::class);
    }
}

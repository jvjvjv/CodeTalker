<?php

namespace Jvjvjv\CodeTalker\Services;

use Jvjvjv\CodeTalker\Enums\AiSystemRole;
use Jvjvjv\CodeTalker\Models\AiSystem;
use RuntimeException;

/**
 * The guards every call path applies before using an AiSystem: it exists, it
 * is active, and it was configured for the role it is about to serve.
 *
 * Shared by the chat path (AgentFactory::systemForFeature()) and the audio
 * services so the three cannot drift.
 */
class AiSystemResolver
{
    /**
     * The default system for a feature, refused unless it is active and has
     * the expected role.
     */
    public function forFeature(string $feature, AiSystemRole $expected): AiSystem
    {
        $system = AiSystem::defaultForFeature($feature);

        if ($system === null) {
            throw new RuntimeException("No default AI system configured for feature: {$feature}");
        }

        if (!$system->is_active) {
            throw new RuntimeException("The default AI system for feature '{$feature}' is inactive.");
        }

        if ($system->role !== $expected) {
            throw new RuntimeException(
                "The default AI system for feature '{$feature}' has the '{$system->role->value}' role, "
                . "but '{$expected->value}' is required."
            );
        }

        return $system;
    }

    /**
     * Apply the same guards to a system the caller already holds.
     */
    public function ensureUsable(AiSystem $system, AiSystemRole $expected): AiSystem
    {
        if (!$system->is_active) {
            throw new RuntimeException("AI system '{$system->name}' is inactive.");
        }

        if ($system->role !== $expected) {
            throw new RuntimeException(
                "AI system '{$system->name}' has the '{$system->role->value}' role, "
                . "but '{$expected->value}' is required."
            );
        }

        return $system;
    }
}

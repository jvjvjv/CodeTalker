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

        $role = $this->roleOf($system);

        if ($role !== $expected) {
            throw new RuntimeException(
                "The default AI system for feature '{$feature}' has the '{$role->value}' role, "
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

        $role = $this->roleOf($system);

        if ($role !== $expected) {
            throw new RuntimeException(
                "AI system '{$system->name}' has the '{$role->value}' role, "
                . "but '{$expected->value}' is required."
            );
        }

        return $system;
    }

    /**
     * A role that is not loaded is refused rather than assumed. The model has
     * none when the row was selected without the column, or when the `role`
     * migration has not run yet; guessing `chat` in either case would let a
     * voice system through the chat guard.
     */
    private function roleOf(AiSystem $system): AiSystemRole
    {
        $role = $system->role;

        if (!$role instanceof AiSystemRole) {
            throw new RuntimeException(
                "AI system '{$system->name}' has no role. Run the package migrations, "
                . 'and include `role` when selecting AiSystem columns.'
            );
        }

        return $role;
    }
}

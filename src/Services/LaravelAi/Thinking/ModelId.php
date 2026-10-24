<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi\Thinking;

/**
 * Reduces a model id to the family name the mapper tables are keyed by:
 * lowercased, with gateway prefixes (`us.anthropic.`, `models/`, `ft:`),
 * version tags (`@…`, `:…`), and date or `-latest` suffixes removed.
 */
final class ModelId
{
    public static function normalize(string $model, string $family): string
    {
        $id = strtolower(trim($model));

        $id = preg_replace('/^ft:/', '', $id);
        $id = preg_replace('#^models/#', '', $id);

        // Keep everything from the family name on (`us.anthropic.claude-…`).
        $position = strpos($id, $family);

        if ($position !== false) {
            $id = substr($id, $position);
        }

        $id = preg_replace('/[@:].*$/', '', $id);
        // Suffixes stack (`-20250929-v1`), so strip until none is left.
        do {
            $previous = $id;
            $id = preg_replace('/-(\d{8}|\d{4}-\d{2}-\d{2}|latest|preview|v\d+)$/', '', $id);
        } while ($id !== $previous);

        return $id;
    }
}

<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Remote;

/**
 * Builds the name a remote MCP tool is known by inside this package.
 *
 * The same string is the tool name sent to the model, the registry key, and
 * the entry an admin puts in `allowed_tools`, so it has to be deterministic:
 * a re-sync that produced a different name would silently revoke a grant.
 *
 * Provider tool names are limited to `[A-Za-z0-9_-]{1,64}` (Anthropic and
 * OpenAI both enforce it). The double underscore separating slug from tool is
 * what marks a name as remote — server slugs cannot contain underscores.
 */
final class ExposedToolName
{
    public const SEPARATOR = '__';

    public const MAX_LENGTH = 64;

    private const HASH_LENGTH = 8;

    public static function for(string $serverSlug, string $remoteName): string
    {
        $sanitized = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $remoteName);
        $name = $serverSlug . self::SEPARATOR . $sanitized;

        if (strlen($name) <= self::MAX_LENGTH) {
            return $name;
        }

        // Hash the original remote name, not the sanitized one, so two long
        // names differing only in replaced characters still come apart.
        $suffix = '_' . substr(sha1($remoteName), 0, self::HASH_LENGTH);

        return substr($name, 0, self::MAX_LENGTH - strlen($suffix)) . $suffix;
    }

    public static function isRemote(string $name): bool
    {
        return str_contains($name, self::SEPARATOR);
    }
}

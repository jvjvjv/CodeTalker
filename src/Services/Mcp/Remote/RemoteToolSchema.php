<?php

namespace Jvjvjv\CodeTalker\Services\Mcp\Remote;

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Schema\SchemaNormalizer;
use RuntimeException;
use Throwable;

/**
 * Converts a remote tool's JSON Schema into the property map a laravel/ai
 * tool declares.
 *
 * The conversion runs on the root schema, not property by property, so
 * `$ref`s into root-level `$defs` resolve. It is the same conversion
 * laravel/ai's own McpTool uses — except that where McpTool falls back to an
 * empty schema on failure, this throws. A tool whose arguments the model
 * cannot see is worse than no tool: the model guesses.
 *
 * SchemaNormalizer inlines every `$ref` where it appears, so a small schema
 * whose definitions reference each other several times over expands
 * exponentially. A budget is checked first, against the schema as it would
 * be expanded, so such a schema is refused before it is converted.
 */
final class RemoteToolSchema
{
    public const MAX_BYTES = 65536;

    public const MAX_REFS = 100;

    public const MAX_DEPTH = 32;

    /** Nodes the schema may expand to once every `$ref` is inlined. */
    public const MAX_EXPANDED_NODES = 20000;

    /**
     * @param array<string, mixed> $inputSchema
     * @return array<string, Type>
     *
     * @throws RuntimeException when the schema cannot be represented
     */
    public static function properties(array $inputSchema): array
    {
        if ($inputSchema === [] || ($inputSchema['properties'] ?? null) === []) {
            return [];
        }

        self::assertWithinBudget($inputSchema);

        try {
            $type = JsonSchema::fromArray(SchemaNormalizer::normalize($inputSchema));
        } catch (Throwable $e) {
            throw new RuntimeException('Input schema cannot be represented: ' . $e->getMessage(), 0, $e);
        }

        if (!$type instanceof ObjectType) {
            throw new RuntimeException('Input schema must describe an object.');
        }

        return (fn (): array => $this->properties)->call($type);
    }

    /**
     * @param array<string, mixed> $inputSchema
     */
    public static function unrepresentableReason(array $inputSchema): ?string
    {
        try {
            self::properties($inputSchema);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $inputSchema
     *
     * @throws RuntimeException naming the budget the schema exceeds
     */
    public static function assertWithinBudget(array $inputSchema): void
    {
        $json = json_encode($inputSchema, 0, self::MAX_DEPTH);

        if ($json === false) {
            throw new RuntimeException('Input schema exceeds the budget: nested deeper than ' . self::MAX_DEPTH . ' levels.');
        }

        if (strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException('Input schema exceeds the budget: larger than ' . self::MAX_BYTES . ' bytes.');
        }

        if (substr_count($json, '"$ref"') > self::MAX_REFS) {
            throw new RuntimeException('Input schema exceeds the budget: more than ' . self::MAX_REFS . ' $ref pointers.');
        }

        $memo = [];

        if (self::expandedSize($inputSchema, $inputSchema, $memo, []) > self::MAX_EXPANDED_NODES) {
            throw new RuntimeException('Input schema exceeds the budget: its $ref pointers expand to more than ' . self::MAX_EXPANDED_NODES . ' nodes.');
        }
    }

    /**
     * Count the nodes a schema expands to with each local `$ref` inlined, as
     * SchemaNormalizer would. A reference's size is memoized, so this costs
     * the schema's own size however far it would expand; a reference already
     * being expanded counts once, matching the normalizer's cycle guard.
     * The count stops growing usefully past the cap, so it is clamped there.
     *
     * @param array<mixed> $node
     * @param array<string, mixed> $root
     * @param array<string, int> $memo
     * @param array<string, true> $expanding
     */
    private static function expandedSize(array $node, array $root, array &$memo, array $expanding): int
    {
        $size = 1;

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $size += self::refSize($value, $root, $memo, $expanding);
            } elseif (is_array($value)) {
                $size += self::expandedSize($value, $root, $memo, $expanding);
            }

            if ($size > self::MAX_EXPANDED_NODES) {
                return self::MAX_EXPANDED_NODES + 1;
            }
        }

        return $size;
    }

    /**
     * @param array<string, mixed> $root
     * @param array<string, int> $memo
     * @param array<string, true> $expanding
     */
    private static function refSize(string $ref, array $root, array &$memo, array $expanding): int
    {
        if (isset($memo[$ref])) {
            return $memo[$ref];
        }

        if (isset($expanding[$ref]) || !str_starts_with($ref, '#/')) {
            return 1;
        }

        $target = $root;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));

            if (!is_array($target) || !array_key_exists($segment, $target)) {
                return 1;
            }

            $target = $target[$segment];
        }

        if (!is_array($target)) {
            return 1;
        }

        $expanding[$ref] = true;

        return $memo[$ref] = self::expandedSize($target, $root, $memo, $expanding);
    }
}

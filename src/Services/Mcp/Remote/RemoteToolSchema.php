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
 */
final class RemoteToolSchema
{
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
}

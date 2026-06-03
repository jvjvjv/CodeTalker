<?php

namespace Jvjvjv\CodeTalker\Contracts\Mcp;

interface AiToolRegistryContract
{
    /**
     * @return array<int, array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function toApiTools(): array;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function dispatch(string $toolName, array $input): array;
}

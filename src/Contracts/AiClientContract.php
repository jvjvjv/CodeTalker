<?php

namespace Jvjvjv\CodeTalker\Contracts;

use Generator;

interface AiClientContract
{
    public function withSystem(string $system): self;

    public function withModel(string $model): self;

    public function withMaxTokens(int $maxTokens): self;

    public function withTemperature(float $temperature): self;

    /**
     * @param array<int, array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     */
    public function withTools(array $tools): self;

    /**
     * @param array<int, array{role: string, content: string|array<int, mixed>}> $messages
     * @return array<string, mixed>
     */
    public function message(array $messages): array;

    /**
     * @param array<int, array{role: string, content: string|array<int, mixed>}> $messages
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(array $messages): Generator;

    /**
     * @return array<int, array{id: string, display_name: string}>
     */
    public function listModels(): array;

    /**
     * Format an assistant turn that includes tool_use blocks alongside optional text.
     *
     * @param array<int, array{id: string, name: string, input: array<string, mixed>}> $toolCalls
     * @return array{role: string, content: mixed}
     */
    public function formatAssistantToolCallTurn(string $textContent, array $toolCalls): array;

    /**
     * Format tool result turn(s) to append after executing tool calls.
     * Anthropic uses one message with multiple tool_result blocks; OpenAI uses one message per result.
     *
     * @param array<int, array{id: string, result: array<string, mixed>}> $toolResults
     * @return array<int, array{role: string, content: mixed}>
     */
    public function formatToolResultTurn(array $toolResults): array;
}

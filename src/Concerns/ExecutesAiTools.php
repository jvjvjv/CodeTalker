<?php

namespace Jvjvjv\CodeTalker\Concerns;

use Jvjvjv\CodeTalker\Contracts\AiClientContract;
use Jvjvjv\CodeTalker\Contracts\Mcp\AiToolRegistryContract;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiLlmMessage;
use Generator;
use Illuminate\Support\Facades\Log;

trait ExecutesAiTools
{
    /**
     * Run the AI streaming loop with tool execution until the model produces
     * a final text response or the iteration cap is reached.
     *
     * Yields SSE-formatted lines for streaming to the client.
     * Populates $result with the final turn's text and token counts.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array{text: string, inputTokens: int|null, outputTokens: int|null}|null $result
     * @return Generator<int, string>
     */
    private function runToolLoop(
        AiClientContract $client,
        array $messages,
        AiToolRegistryContract $toolRegistry,
        int $maxIterations = 6,
        ?array &$result = null,
        ?AiConversation $conversation = null,
    ): Generator {
        $preambleText = '';
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $finalTextAccumulator = ''; // accumulates text across max_tokens continuation calls

        // Compute the base turn number once, before any iterations
        $baseTurnNumber = null;
        if ($conversation !== null) {
            $max = AiLlmMessage::query()
                ->where('ai_conversation_id', $conversation->id)
                ->max('turn_number');
            $baseTurnNumber = ($max === null || !is_numeric($max)) ? 1 : (int) $max + 1;
        }

        yield ": heartbeat\n\n";

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $iterationStartTime = microtime(true);
            $iterationTurnNumber = $baseTurnNumber !== null
                ? ($iteration === 0 ? (string) $baseTurnNumber : "{$baseTurnNumber}.{$iteration}")
                : null;

            $iterationRequestPayload = ['messages' => $messages];
            if ($conversation !== null) {
                $iterationRequestPayload['model'] = $conversation->aiSystem->model;
                $iterationRequestPayload['max_tokens'] = $conversation->aiSystem->max_tokens;

                AiLlmMessage::create([
                    'ai_conversation_id' => $conversation->id,
                    'direction' => 'request',
                    'turn_number' => $iterationTurnNumber,
                    'request_data' => $iterationRequestPayload,
                    'created_at' => now(),
                ]);
            }

            $stream = $client->withTools($toolRegistry->toApiTools())->stream($messages);

            $fullText = '';
            $fullReasoning = '';
            $stopReason = 'end_turn';
            $inputTokens = null;
            $outputTokens = null;

            /** @var array<string, array{id: string, name: string, partialJson: string}> $pendingToolBlocks */
            $pendingToolBlocks = [];
            $currentBlockKey = null;

            foreach ($stream as $event) {
                $type = $event['type'] ?? '';

                if ($type === 'message_start') {
                    $inputTokens = $event['message']['usage']['input_tokens'] ?? $inputTokens;

                } elseif ($type === 'content_block_start') {
                    $block = $event['content_block'] ?? [];

                    if (($block['type'] ?? '') === 'tool_use') {
                        $id = (string) ($block['id'] ?? uniqid('tool_', true));
                        $currentBlockKey = $id;
                        $pendingToolBlocks[$id] = [
                            'id' => $id,
                            'name' => (string) ($block['name'] ?? ''),
                            'partialJson' => '',
                        ];
                    } elseif (($block['type'] ?? '') === 'thinking') {
                        yield "data: " . json_encode($event) . "\n\n";
                    }

                } elseif ($type === 'content_block_delta') {
                    $delta = $event['delta'] ?? [];
                    $deltaType = $delta['type'] ?? '';

                    if ($deltaType === 'input_json_delta' && $currentBlockKey !== null && isset($pendingToolBlocks[$currentBlockKey])) {
                        $pendingToolBlocks[$currentBlockKey]['partialJson'] .= (string) ($delta['partial_json'] ?? '');
                    } elseif ($deltaType === 'thinking_delta' || isset($delta['reasoning'])) {
                        $fullReasoning .= (string) ($delta['thinking'] ?? $delta['reasoning'] ?? '');
                        yield "data: " . json_encode($event) . "\n\n";
                    } elseif (isset($delta['text'])) {
                        $fullText .= $delta['text'];
                        yield "data: " . json_encode($event) . "\n\n";
                    }

                } elseif ($type === 'content_block_stop') {
                    $currentBlockKey = null;

                } elseif ($type === 'message_delta') {
                    $outputTokens = $event['usage']['output_tokens'] ?? $outputTokens;
                    $inputTokens  = $event['usage']['input_tokens']  ?? $inputTokens;
                    $reason = $event['delta']['stop_reason'] ?? null;

                    if ($reason !== null) {
                        $stopReason = (string) $reason;
                    }
                }
                // message_stop is deferred until we know this is the final turn
            }

            // Parse accumulated tool call JSON
            $toolCalls = [];

            foreach ($pendingToolBlocks as $block) {
                $input = json_decode($block['partialJson'], true);
                $toolCalls[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'input' => \is_array($input) ? $input : [],
                ];
            }

            $totalInputTokens += (int) ($inputTokens ?? 0);
            $totalOutputTokens += (int) ($outputTokens ?? 0);

            if ($conversation !== null && $iterationTurnNumber !== null) {
                $contentBlocks = [];
                if ($fullReasoning !== '') {
                    $contentBlocks[] = ['type' => 'thinking', 'thinking' => $fullReasoning];
                }
                if ($fullText !== '') {
                    $contentBlocks[] = ['type' => 'text', 'text' => $fullText];
                }
                foreach ($toolCalls as $tc) {
                    $contentBlocks[] = ['type' => 'tool_use', 'id' => $tc['id'], 'name' => $tc['name'], 'input' => $tc['input']];
                }

                AiLlmMessage::create([
                    'ai_conversation_id' => $conversation->id,
                    'direction' => 'response',
                    'turn_number' => $iterationTurnNumber,
                    'request_data' => $iterationRequestPayload,
                    'response_data' => [
                        'stop_reason' => $stopReason,
                        'input_tokens' => $inputTokens,
                        'output_tokens' => $outputTokens,
                        'model' => $conversation->aiSystem->model,
                        'text_length' => strlen($fullText),
                        'reasoning_length' => strlen($fullReasoning),
                        'tool_calls' => array_map(
                            static fn (array $tc): array => ['id' => $tc['id'], 'name' => $tc['name']],
                            $toolCalls,
                        ),
                    ],
                    'raw_response' => [
                        'model' => $conversation->aiSystem->model,
                        'stop_reason' => $stopReason,
                        'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
                        'content' => $contentBlocks,
                    ],
                    'duration_ms' => (int) ((microtime(true) - $iterationStartTime) * 1000),
                    'created_at' => now(),
                ]);
            }

            // No tool calls — this is the final response (or a max_tokens continuation)
            if ($stopReason !== 'tool_use' || $toolCalls === []) {
                $finalTextAccumulator .= $fullText;

                Log::debug('ExecutesAiTools: stop check', [
                    'iteration' => $iteration,
                    'stop_reason' => $stopReason,
                    'full_text_length' => strlen($fullText),
                    'accumulator_length' => strlen($finalTextAccumulator),
                ]);

                if ($stopReason === 'max_tokens' && $finalTextAccumulator !== '') {
                    // Response was cut off at the token limit — send it back and ask the model to continue
                    $messages[] = ['role' => 'assistant', 'content' => $finalTextAccumulator];
                    $messages[] = ['role' => 'user', 'content' => 'Continue.'];
                    continue;
                }

                $combinedText = $preambleText !== ''
                    ? $preambleText . ($finalTextAccumulator !== '' ? "\n\n{$finalTextAccumulator}" : '')
                    : $finalTextAccumulator;

                $result = [
                    'text' => $combinedText,
                    'inputTokens' => $totalInputTokens ?: null,
                    'outputTokens' => $totalOutputTokens ?: null,
                ];

                yield "data: " . json_encode(['type' => 'message_stop']) . "\n\n";

                return;
            }

            // Accumulate preamble text from this tool-calling iteration so it
            // is included in the final saved message even if the follow-up
            // iteration produces no additional text.
            if ($fullText !== '') {
                $preambleText .= ($preambleText !== '' ? "\n\n" : '') . $fullText;
            }
            $finalTextAccumulator = ''; // reset — this iteration had tool calls, not a continuation

            // Notify the frontend that tool calls are happening so it can display a panel.
            // Include any preamble text the model wrote before calling tools.
            yield "data: " . json_encode([
                'type' => 'tool_use_progress',
                'text' => $fullText,
                'tools' => array_column($toolCalls, 'name'),
            ]) . "\n\n";

            // Execute tool calls and splice results back into the message history
            $toolResults = [];

            foreach ($toolCalls as $toolCall) {
                Log::debug('Executing AI tool call', [
                    'tool' => $toolCall['name'],
                    'input' => $toolCall['input'],
                ]);

                try {
                    $toolResult = $toolRegistry->dispatch($toolCall['name'], $toolCall['input']);
                } catch (\Throwable $e) {
                    Log::warning('AI tool call failed', [
                        'tool' => $toolCall['name'],
                        'error' => $e->getMessage(),
                    ]);
                    $toolResult = ['error' => $e->getMessage()];
                }

                if (!empty($toolResult['_page_reload'])) {
                    yield "data: " . json_encode(['type' => 'page_reload']) . "\n\n";
                }

                $toolResults[] = [
                    'id' => $toolCall['id'],
                    'name' => $toolCall['name'],
                    'result' => array_filter(
                        $toolResult,
                        static fn (string $key): bool => $key !== '_page_reload',
                        ARRAY_FILTER_USE_KEY,
                    ),
                ];
            }

            yield ": heartbeat\n\n";

            $messages[] = $client->formatAssistantToolCallTurn($fullText, $toolCalls);

            foreach ($client->formatToolResultTurn($toolResults) as $resultMessage) {
                $messages[] = $resultMessage;
            }
        }

        // Safety cap reached
        Log::warning('AI tool loop reached max iterations', ['maxIterations' => $maxIterations]);

        $result = [
            'text' => $preambleText,
            'inputTokens' => $totalInputTokens ?: null,
            'outputTokens' => $totalOutputTokens ?: null,
        ];

        yield "data: " . json_encode(['type' => 'message_stop']) . "\n\n";
    }
}

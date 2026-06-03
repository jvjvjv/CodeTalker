<?php

namespace Jvjvjv\CodeTalker\Services;

use Jvjvjv\CodeTalker\Contracts\AiClientContract;
use Jvjvjv\CodeTalker\Contracts\CanLoadModels;
use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LmStudioService implements AiClientContract, CanLoadModels
{
    private string $defaultModel;
    private int $defaultMaxTokens;
    private ?int $defaultContextLength;

    /** The root URL, e.g. http://localhost:1234 */
    private string $serverUrl;

    private ?string $apiKey;
    private ?string $system = null;
    private ?string $model = null;
    private ?int $maxTokens = null;
    private ?int $contextLength = null;
    private ?float $temperature = null;

    /** @var array<int, array{name: string, description: string, input_schema: array<string, mixed>}> */
    private array $tools = [];

    public function __construct(
        ?string $serverUrl = null,
        ?string $model = null,
        ?int $maxTokens = null,
        ?int $contextLength = null,
        ?string $apiKey = null,
        private bool $enableThinking = false,
    ) {
        $this->serverUrl = $this->normalizeServerUrl(
            $serverUrl ?? config('code-talker.providers.lm-studio.server_url', 'http://localhost:1234'),
        );
        $this->defaultModel = $model ?? config('code-talker.providers.lm-studio.model', '');
        $this->defaultMaxTokens = $maxTokens ?? (int) config('code-talker.providers.lm-studio.max_tokens', 1024);
        $this->defaultContextLength = $contextLength;
        $this->apiKey = $apiKey;
    }

    public function withSystem(string $system): self
    {
        $this->system = $system;

        return $this;
    }

    public function withModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function withMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;

        return $this;
    }

    public function withTemperature(float $temperature): self
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function withTools(array $tools): self
    {
        $this->tools = $tools;

        return $this;
    }

    public function message(array $messages): array
    {
        $payload = $this->buildOpenAiPayload($messages, false);

        $response = Http::withHeaders($this->headers())
            ->timeout(600)
            ->post($this->serverUrl . '/v1/chat/completions', $payload);

        $this->reset();

        $response->throw();

        $data = $response->json();
        $choice = $data['choices'][0] ?? [];
        $content = $choice['message']['content'] ?? '';

        if (is_array($content)) {
            $content = collect($content)
                ->map(static fn (array $part): string => (string) ($part['text'] ?? ''))
                ->implode('');
        }

        return [
            'id' => (string) ($data['id'] ?? ''),
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => (string) $content,
                ],
            ],
            'reasoning_content' => $choice['message']['reasoning_content'] ?? null,
            'model' => (string) ($data['model'] ?? ($this->model ?? $this->defaultModel)),
            'stop_reason' => (string) ($choice['finish_reason'] ?? 'stop'),
            'usage' => [
                'input_tokens' => (int) ($data['usage']['prompt_tokens'] ?? 0),
                'output_tokens' => (int) ($data['usage']['completion_tokens'] ?? 0),
            ],
        ];
    }

    public function stream(array $messages): Generator
    {
        $payload = $this->buildOpenAiPayload($messages, true);

        Log::debug('LmStudio stream payload', ['message_count' => count($payload['messages']), 'messages' => $payload['messages']]);

        $response = Http::withHeaders($this->headers())
            ->withOptions(['stream' => true])
            ->timeout(600)
            ->post($this->serverUrl . '/v1/chat/completions', $payload);

        $this->reset();

        $response->throw();

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $inputTokens = null;
        $outputTokens = null;
        $started = false;

        // Track in-flight tool calls: index → {id, name, arguments}
        /** @var array<int, array{id: string, name: string, arguments: string}> $pendingToolCalls */
        $pendingToolCalls = [];
        $finishReason = null;

        while (!$body->eof()) {
            $buffer .= $body->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line === '' || !str_starts_with($line, 'data: ')) {
                    continue;
                }

                $rawData = substr($line, 6);

                if ($rawData === '[DONE]') {
                    break 2;
                }

                $chunk = json_decode($rawData, true);

                if (!is_array($chunk)) {
                    continue;
                }

                if (isset($chunk['usage'])) {
                    $inputTokens = isset($chunk['usage']['prompt_tokens']) ? (int) $chunk['usage']['prompt_tokens'] : $inputTokens;
                    $outputTokens = isset($chunk['usage']['completion_tokens']) ? (int) $chunk['usage']['completion_tokens'] : $outputTokens;
                }

                if (!$started) {
                    $started = true;

                    yield [
                        'type' => 'message_start',
                        'message' => [
                            'usage' => [
                                'input_tokens' => $inputTokens,
                            ],
                        ],
                    ];
                }

                $choice = $chunk['choices'][0] ?? [];
                $delta = $choice['delta'] ?? [];
                $text = $delta['content'] ?? null;
                $reasoning = $delta['reasoning_content'] ?? null;

                if (is_string($reasoning) && $reasoning !== '') {
                    yield [
                        'type' => 'reasoning_block_delta',
                        'delta' => [
                            'reasoning' => $reasoning,
                        ],
                    ];
                }

                if (is_string($text) && $text !== '') {
                    yield [
                        'type' => 'content_block_delta',
                        'delta' => [
                            'text' => $text,
                        ],
                    ];
                }

                // Accumulate streaming tool_calls fragments (delta format)
                if (!empty($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tcDelta) {
                        $idx = (int) ($tcDelta['index'] ?? 0);

                        if (!isset($pendingToolCalls[$idx])) {
                            $pendingToolCalls[$idx] = ['id' => '', 'name' => '', 'arguments' => ''];
                        }

                        if (isset($tcDelta['id']) && $tcDelta['id'] !== '') {
                            $pendingToolCalls[$idx]['id'] = $tcDelta['id'];
                        }

                        if (isset($tcDelta['function']['name']) && $tcDelta['function']['name'] !== '') {
                            $pendingToolCalls[$idx]['name'] = $tcDelta['function']['name'];
                        }

                        if (isset($tcDelta['function']['arguments'])) {
                            $pendingToolCalls[$idx]['arguments'] .= $tcDelta['function']['arguments'];
                        }
                    }
                }

                // LM Studio fallback: complete tool_calls in choice['message'] instead of delta
                if (empty($delta['tool_calls']) && !empty($choice['message']['tool_calls'])) {
                    foreach ($choice['message']['tool_calls'] as $i => $tc) {
                        $pendingToolCalls[$i] = [
                            'id' => (string) ($tc['id'] ?? ''),
                            'name' => (string) ($tc['function']['name'] ?? ''),
                            'arguments' => (string) ($tc['function']['arguments'] ?? ''),
                        ];
                    }
                }

                if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                    $finishReason = $choice['finish_reason'];
                    Log::debug('LmStudio finish_reason chunk', ['chunk' => $chunk]);
                }
            }
        }

        // Emit accumulated tool calls as normalized Anthropic-style events
        foreach ($pendingToolCalls as $toolCall) {
            yield [
                'type' => 'content_block_start',
                'content_block' => [
                    'type' => 'tool_use',
                    'id' => $toolCall['id'],
                    'name' => $toolCall['name'],
                ],
            ];

            yield [
                'type' => 'content_block_delta',
                'delta' => [
                    'type' => 'input_json_delta',
                    'partial_json' => $toolCall['arguments'],
                ],
            ];

            yield ['type' => 'content_block_stop'];
        }

        if ($started === false) {
            yield [
                'type' => 'message_start',
                'message' => [
                    'usage' => [
                        'input_tokens' => $inputTokens,
                    ],
                ],
            ];
        }

        $stopReason = match ($finishReason) {
            'tool_calls' => 'tool_use',
            'length' => 'max_tokens',
            default => 'end_turn',
        };

        yield [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => $stopReason],
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
            ],
        ];

        yield [
            'type' => 'message_stop',
        ];
    }

    /**
     * Lists all models available on disk (loaded and unloaded).
     * Uses the native LM Studio /api/v1/models endpoint.
     *
     * {@inheritDoc}
     */
    public function listModels(): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(15)
            ->get($this->serverUrl . '/api/v1/models');

        $response->throw();

        $models = $response->json('models', []);

        if (!is_array($models)) {
            return [];
        }

        return collect($models)
            ->filter(static fn (mixed $m): bool => is_array($m) && isset($m['key']) && ($m['type'] ?? '') === 'llm')
            ->map(static function (array $m): array {
                $capabilities = is_array($m['capabilities'] ?? null) ? $m['capabilities'] : [];
                $reasoning = $capabilities['reasoning'] ?? null;

                return [
                    'id' => (string) $m['key'],
                    'display_name' => (string) ($m['display_name'] ?? $m['key']),
                    'loaded' => !empty($m['loaded_instances']),
                    'max_context_length' => isset($m['max_context_length']) ? (int) $m['max_context_length'] : null,
                    'capabilities' => [
                        'vision' => (bool) ($capabilities['vision'] ?? false),
                        'tools' => (bool) ($capabilities['trained_for_tool_use'] ?? false),
                        'reasoning' => is_array($reasoning) || (bool) $reasoning,
                    ],
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Returns true if the model currently has at least one active loaded instance.
     */
    public function isModelLoaded(string $model): bool
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(15)
            ->get($this->serverUrl . '/api/v1/models');

        if ($response->failed()) {
            return false;
        }

        $models = $response->json('models', []);

        if (!is_array($models)) {
            return false;
        }

        return collect($models)
            ->contains(static function (mixed $m) use ($model): bool {
                if (!is_array($m)) {
                    return false;
                }

                return strcasecmp((string) ($m['key'] ?? ''), $model) === 0
                    && !empty($m['loaded_instances']);
            });
    }

    /**
     * Explicitly loads the model into memory via the LM Studio native API.
     *
     * @return array{status: string, instance_id: string, load_time_seconds: float}
     */
    public function loadModel(string $model, ?int $contextLength = null): array
    {
        $payload = [
            'model' => $model,
        ];

        $resolvedContextLength = $contextLength ?? $this->contextLength ?? $this->defaultContextLength;

        if ($resolvedContextLength !== null) {
            $payload['context_length'] = $resolvedContextLength;
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(300)
            ->post($this->serverUrl . '/api/v1/models/load', $payload);

        $response->throw();

        $data = $response->json();

        return [
            'status' => (string) ($data['status'] ?? 'loaded'),
            'instance_id' => (string) ($data['instance_id'] ?? $model),
            'load_time_seconds' => (float) ($data['load_time_seconds'] ?? 0.0),
        ];
    }

    /**
     * @param array<int, array{id: string, name: string, input: array<string, mixed>}> $toolCalls
     * @return array{role: string, content: string|null, tool_calls: array<int, mixed>}
     */
    public function formatAssistantToolCallTurn(string $textContent, array $toolCalls): array
    {
        $formattedCalls = [];

        foreach ($toolCalls as $toolCall) {
            $formattedCalls[] = [
                'id' => $toolCall['id'],
                'type' => 'function',
                'function' => [
                    'name' => $toolCall['name'],
                    'arguments' => json_encode(
                        $toolCall['input'] === [] ? new \stdClass() : $toolCall['input']
                    ),
                ],
            ];
        }

        return [
            'role' => 'assistant',
            'content' => $textContent !== '' ? $textContent : null,
            'tool_calls' => $formattedCalls,
        ];
    }

    /**
     * @param array<int, array{id: string, result: array<string, mixed>}> $toolResults
     * @return array<int, array{role: string, tool_call_id: string, content: string}>
     */
    public function formatToolResultTurn(array $toolResults): array
    {
        return array_map(static fn (array $result): array => [
            'role' => 'tool',
            'tool_call_id' => $result['tool_use_id'] ?? $result['id'],
            'content' => json_encode($result['result']),
        ], $toolResults);
    }

    /**
     * @param array<int, array{role: string, content: string|array<int, mixed>}> $messages
     */
    private function buildOpenAiPayload(array $messages, bool $streaming): array
    {
        $payload = [
            'model' => $this->model ?? $this->defaultModel,
            'max_tokens' => $this->maxTokens ?? $this->defaultMaxTokens,
            'messages' => $this->buildMessages($messages),
        ];

        if ($this->temperature !== null) {
            $payload['temperature'] = $this->temperature;
        }

        if ($streaming) {
            $payload['stream'] = true;
            $payload['stream_options'] = ['include_usage' => true];
        }

        $payload['enable_thinking'] = $this->enableThinking;

        if ($this->tools !== []) {
            $payload['tools'] = collect($this->tools)
                ->map(static fn (array $tool): array => [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                        'parameters' => $tool['input_schema'],
                    ],
                ])
                ->values()
                ->toArray();
        }

        return $payload;
    }

    /**
     * @param array<int, array{role: string, content: string|array<int, mixed>}> $messages
     * @return array<int, array{role: string, content: string|array<int, mixed>}>
     */
    private function buildMessages(array $messages): array
    {
        if ($this->system === null || $this->system === '') {
            return $messages;
        }

        return [
            ['role' => 'system', 'content' => $this->system],
            ...$messages,
        ];
    }

    private function reset(): void
    {
        $this->system = null;
        $this->model = null;
        $this->maxTokens = null;
        $this->contextLength = null;
        $this->temperature = null;
        $this->tools = [];
    }

    private function normalizeServerUrl(string $serverUrl): string {
        $normalized = rtrim($serverUrl, '/');

        if (str_ends_with($normalized, '/api/v1')) {
            $normalized = substr($normalized, 0, -7);
        } elseif (str_ends_with($normalized, '/api')) {
            $normalized = substr($normalized, 0, -4);
        } elseif (str_ends_with($normalized, '/v1')) {
            $normalized = substr($normalized, 0, -3);
        }

        return rtrim($normalized, '/');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = ['content-type' => 'application/json'];

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        return $headers;
    }
}

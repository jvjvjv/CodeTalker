<?php

namespace Jvjvjv\CodeTalker\Services;

use Jvjvjv\CodeTalker\Contracts\AiClientContract;
use Generator;
use OpenAI\Client as OpenAIClient;
use OpenAI\Responses\Chat\CreateStreamedResponseToolCall;

class OpenAiService implements AiClientContract
{
    private OpenAIClient $client;
    private string $defaultModel;
    private int $defaultMaxTokens;

    private ?string $system = null;
    private ?string $model = null;
    private ?int $maxTokens = null;
    private ?float $temperature = null;

    /** @var array<int, array{name: string, description: string, input_schema: array<string, mixed>}> */
    private array $tools = [];

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?int $maxTokens = null,
        ?string $baseUrl = null,
    ) {
        $this->defaultModel = $model ?? config('code-talker.providers.openai.model', 'gpt-4o-mini');
        $this->defaultMaxTokens = $maxTokens ?? (int) config('code-talker.providers.openai.max_tokens', 1024);

        $resolvedBaseUrl = rtrim($baseUrl ?? config('code-talker.providers.openai.base_url', 'https://api.openai.com/v1'), '/');
        $resolvedApiKey = $apiKey ?? '';

        $factory = \OpenAI::factory()->withBaseUri($resolvedBaseUrl);

        if ($resolvedApiKey !== '') {
            $factory = $factory->withApiKey($resolvedApiKey);
        }

        $this->client = $factory->make();
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
        $params = $this->buildParams($messages, false);

        $response = $this->client->chat()->create($params);

        $this->reset();

        $choice = $response->choices[0] ?? null;
        $content = $choice?->message->content ?? '';

        return [
            'id' => $response->id ?? '',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => (string) $content],
            ],
            'reasoning_content' => $choice?->message->reasoningContent ?? null,
            'model' => $response->model ?? ($this->model ?? $this->defaultModel),
            'stop_reason' => (string) ($choice?->finishReason ?? 'stop'),
            'usage' => [
                'input_tokens' => (int) ($response->usage?->promptTokens ?? 0),
                'output_tokens' => (int) ($response->usage?->completionTokens ?? 0),
            ],
        ];
    }

    public function stream(array $messages): Generator
    {
        $params = $this->buildParams($messages, true);

        $sdkStream = $this->client->chat()->createStreamed($params);

        $this->reset();

        $inputTokens = null;
        $outputTokens = null;
        $started = false;

        /** @var array<int, array{id: string, name: string, arguments: string}> $pendingToolCalls */
        $pendingToolCalls = [];
        $finishReason = null;

        foreach ($sdkStream as $response) {
            if ($response->usage !== null) {
                $inputTokens = $response->usage->promptTokens ?? $inputTokens;
                $outputTokens = $response->usage->completionTokens ?? $outputTokens;
            }

            if (!$started) {
                $started = true;
                yield [
                    'type' => 'message_start',
                    'message' => [
                        'usage' => ['input_tokens' => $inputTokens],
                    ],
                ];
            }

            $choice = $response->choices[0] ?? null;

            if ($choice === null) {
                continue;
            }

            $delta = $choice->delta;
            $text = $delta->content;
            $reasoning = $delta->reasoningContent;

            if (is_string($reasoning) && $reasoning !== '') {
                yield [
                    'type' => 'reasoning_block_delta',
                    'delta' => ['reasoning' => $reasoning],
                ];
            }

            if (is_string($text) && $text !== '') {
                yield [
                    'type' => 'content_block_delta',
                    'delta' => ['text' => $text],
                ];
            }

            foreach ($delta->toolCalls as $tcDelta) {
                /** @var CreateStreamedResponseToolCall $tcDelta */
                $idx = (int) ($tcDelta->index ?? 0);

                if (!isset($pendingToolCalls[$idx])) {
                    $pendingToolCalls[$idx] = ['id' => '', 'name' => '', 'arguments' => ''];
                }

                if ($tcDelta->id !== null && $tcDelta->id !== '') {
                    $pendingToolCalls[$idx]['id'] = $tcDelta->id;
                }

                if ($tcDelta->function->name !== null && $tcDelta->function->name !== '') {
                    $pendingToolCalls[$idx]['name'] = $tcDelta->function->name;
                }

                $pendingToolCalls[$idx]['arguments'] .= $tcDelta->function->arguments;
            }

            if ($choice->finishReason !== null) {
                $finishReason = $choice->finishReason;
            }
        }

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

        if (!$started) {
            yield [
                'type' => 'message_start',
                'message' => [
                    'usage' => ['input_tokens' => $inputTokens],
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
            'usage' => ['output_tokens' => $outputTokens],
        ];

        yield ['type' => 'message_stop'];
    }

    public function listModels(): array
    {
        $response = $this->client->models()->list();

        return collect($response->data)
            ->filter(static fn (mixed $model): bool => isset($model->id))
            ->map(static fn ($model): array => [
                'id' => (string) $model->id,
                'display_name' => (string) $model->id,
            ])
            ->values()
            ->toArray();
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
     * @return array<string, mixed>
     */
    private function buildParams(array $messages, bool $streaming): array
    {
        $params = [
            'model' => $this->model ?? $this->defaultModel,
            'max_tokens' => $this->maxTokens ?? $this->defaultMaxTokens,
            'messages' => $this->buildMessages($messages),
        ];

        if ($this->temperature !== null) {
            $params['temperature'] = $this->temperature;
        }

        if ($streaming) {
            $params['stream'] = true;
            $params['stream_options'] = ['include_usage' => true];
        }

        if ($this->tools !== []) {
            $params['tools'] = collect($this->tools)
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

        return $params;
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
        $this->temperature = null;
        $this->tools = [];
    }
}

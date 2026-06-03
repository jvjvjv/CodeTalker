<?php

namespace Jvjvjv\CodeTalker\Services;

use Anthropic\Client as AnthropicClient;
use Anthropic\Messages\InputJSONDelta;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\RawContentBlockStartEvent;
use Anthropic\Messages\RawContentBlockStopEvent;
use Anthropic\Messages\RawMessageDeltaEvent;
use Anthropic\Messages\RawMessageStartEvent;
use Anthropic\Messages\RawMessageStopEvent;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\TextDelta;
use Anthropic\Messages\ThinkingDelta;
use Anthropic\Messages\ToolUseBlock;
use Jvjvjv\CodeTalker\Contracts\AiClientContract;
use Generator;

class ClaudeService implements AiClientContract
{
    private AnthropicClient $client;
    private string $defaultModel;
    private int $defaultMaxTokens;

    /** @var string|null Per-request system prompt */
    private ?string $system = null;

    /** @var string|null Per-request model override */
    private ?string $model = null;

    /** @var int|null Per-request max_tokens override */
    private ?int $maxTokens = null;

    /** @var float|null Per-request temperature override */
    private ?float $temperature = null;

    /** @var array<int, array<string, mixed>> Per-request tools (user-defined or server-side) */
    private array $tools = [];

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?int $maxTokens = null,
        ?string $apiVersion = null,
        ?string $baseUrl = null,
    ) {
        $this->defaultModel = $model ?? config('code-talker.providers.anthropic.model', 'claude-sonnet-4-6');
        $this->defaultMaxTokens = $maxTokens ?? (int) config('code-talker.providers.anthropic.max_tokens', 1024);

        $this->client = new AnthropicClient(
            apiKey: $apiKey ?? '',
            baseUrl: $baseUrl ?? config('code-talker.providers.anthropic.base_url', 'https://api.anthropic.com'),
        );
    }

    /**
     * Set a system prompt for the next request.
     */
    public function withSystem(string $system): self
    {
        $this->system = $system;

        return $this;
    }

    /**
     * Override the model for the next request.
     */
    public function withModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Override max tokens for the next request.
     */
    public function withMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;

        return $this;
    }

    /**
     * Set the temperature for the next request (0.0 to 1.0).
     */
    public function withTemperature(float $temperature): self
    {
        $this->temperature = $temperature;

        return $this;
    }

    /**
     * Attach tools for the next request.
     *
     * Accepts user-defined tools (name, description, input_schema) or
     * server-side tool declarations (type, name) such as web_search_20260209.
     *
     * @param array<int, array<string, mixed>> $tools
     */
    public function withTools(array $tools): self
    {
        $this->tools = $tools;

        return $this;
    }

    /**
     * Enable Anthropic's server-side web search and web fetch tools for the next request.
     *
     * These are executed on Anthropic's infrastructure — no client-side handling required.
     * The model searches the web automatically when it determines it is necessary.
     */
    public function withWebSearch(): self
    {
        $this->tools = [
            ['type' => 'web_search_20260209', 'name' => 'web_search'],
            ['type' => 'web_fetch_20260209', 'name' => 'web_fetch'],
        ];

        return $this;
    }

    /**
     * Send a message and return the parsed response.
     *
     * @param array<int, array{role: string, content: string|array<int, mixed>}> $messages
     * @return array{id: string, type: string, role: string, content: array<int, mixed>, model: string, stop_reason: string, usage: array{input_tokens: int, output_tokens: int}}
     */
    public function message(array $messages): array
    {
        $resolvedModel = $this->model ?? $this->defaultModel;

        $response = $this->client->messages->create(
            maxTokens: $this->maxTokens ?? $this->defaultMaxTokens,
            messages: $messages,
            model: $resolvedModel,
            system: $this->system,
            temperature: ($this->temperature !== null && $resolvedModel !== 'claude-opus-4-7') ? $this->temperature : null,
            tools: $this->tools !== [] ? $this->tools : null,
        );

        $this->reset();

        $content = [];
        foreach ($response->content as $block) {
            if ($block instanceof TextBlock) {
                $content[] = ['type' => 'text', 'text' => $block->text];
            } elseif ($block instanceof ToolUseBlock) {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $block->id,
                    'name' => $block->name,
                    'input' => $block->input,
                ];
            }
        }

        return [
            'id' => $response->id,
            'type' => $response->type,
            'role' => $response->role,
            'content' => $content,
            'model' => $response->model,
            'stop_reason' => $response->stopReason ?? 'end_turn',
            'usage' => [
                'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens,
            ],
        ];
    }

    /**
     * Send a message with streaming enabled.
     *
     * Yields decoded SSE event arrays. Use the 'type' key to distinguish
     * event types (e.g., 'content_block_delta', 'message_stop').
     *
     * @param array<int, array{role: string, content: string|array<int, mixed>}> $messages
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(array $messages): Generator
    {
        $resolvedModel = $this->model ?? $this->defaultModel;

        $sdkStream = $this->client->messages->createStream(
            maxTokens: $this->maxTokens ?? $this->defaultMaxTokens,
            messages: $messages,
            model: $resolvedModel,
            system: $this->system,
            temperature: ($this->temperature !== null && $resolvedModel !== 'claude-opus-4-7') ? $this->temperature : null,
            tools: $this->tools !== [] ? $this->tools : null,
        );

        $this->reset();

        foreach ($sdkStream as $event) {
            if ($event instanceof RawMessageStartEvent) {
                yield [
                    'type' => 'message_start',
                    'message' => [
                        'usage' => ['input_tokens' => $event->message->usage->inputTokens],
                    ],
                ];
            } elseif ($event instanceof RawContentBlockStartEvent) {
                $block = $event->contentBlock;
                if ($block instanceof ToolUseBlock) {
                    yield [
                        'type' => 'content_block_start',
                        'content_block' => [
                            'type' => 'tool_use',
                            'id' => $block->id,
                            'name' => $block->name,
                        ],
                    ];
                }
            } elseif ($event instanceof RawContentBlockDeltaEvent) {
                $delta = $event->delta;
                if ($delta instanceof TextDelta) {
                    yield [
                        'type' => 'content_block_delta',
                        'delta' => ['text' => $delta->text],
                    ];
                } elseif ($delta instanceof ThinkingDelta) {
                    yield [
                        'type' => 'reasoning_block_delta',
                        'delta' => ['reasoning' => $delta->thinking],
                    ];
                } elseif ($delta instanceof InputJSONDelta) {
                    yield [
                        'type' => 'content_block_delta',
                        'delta' => [
                            'type' => 'input_json_delta',
                            'partial_json' => $delta->partialJSON,
                        ],
                    ];
                }
            } elseif ($event instanceof RawContentBlockStopEvent) {
                yield ['type' => 'content_block_stop'];
            } elseif ($event instanceof RawMessageDeltaEvent) {
                yield [
                    'type' => 'message_delta',
                    'delta' => ['stop_reason' => $event->delta->stopReason],
                    'usage' => ['output_tokens' => $event->usage->outputTokens],
                ];
            } elseif ($event instanceof RawMessageStopEvent) {
                yield ['type' => 'message_stop'];
            }
        }
    }

    /**
     * List available models from the Anthropic API.
     *
     * @return array<int, array{id: string, display_name: string, created_at: string}>
     */
    public function listModels(): array
    {
        $page = $this->client->models->list(limit: 100);

        return collect($page->data)
            ->map(static fn($model): array => [
                'id' => $model->id,
                'display_name' => $model->displayName,
                'created_at' => $model->createdAt->format(\DateTimeInterface::ATOM),
            ])
            ->values()
            ->toArray();
    }

    /**
     * @param array<int, array{id: string, name: string, input: array<string, mixed>}> $toolCalls
     * @return array{role: string, content: array<int, mixed>}
     */
    public function formatAssistantToolCallTurn(string $textContent, array $toolCalls): array
    {
        $content = [];

        if ($textContent !== '') {
            $content[] = ['type' => 'text', 'text' => $textContent];
        }

        foreach ($toolCalls as $toolCall) {
            $content[] = [
                'type' => 'tool_use',
                'id' => $toolCall['id'],
                'name' => $toolCall['name'],
                'input' => (object) $toolCall['input'],
            ];
        }

        return ['role' => 'assistant', 'content' => $content];
    }

    /**
     * @param array<int, array{id: string, result: array<string, mixed>}> $toolResults
     * @return array<int, array{role: string, content: array<int, mixed>}>
     */
    public function formatToolResultTurn(array $toolResults): array
    {
        $content = [];

        foreach ($toolResults as $result) {
            $content[] = [
                'type' => 'tool_result',
                'tool_use_id' => $result['id'],
                'content' => json_encode($result['result']),
            ];
        }

        return [['role' => 'user', 'content' => $content]];
    }

    /**
     * Reset per-request overrides back to defaults.
     */
    private function reset(): void
    {
        $this->system = null;
        $this->model = null;
        $this->maxTokens = null;
        $this->temperature = null;
        $this->tools = [];
    }
}

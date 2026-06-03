<?php

namespace Jvjvjv\CodeTalker\Services;

use Jvjvjv\CodeTalker\Contracts\AiClientContract;
use Gemini\Client as GeminiClient;
use Gemini\Data\Content;
use Gemini\Data\GenerationConfig;
use Gemini\Data\Part;
use Gemini\Enums\FinishReason;
use Gemini\Enums\Role;
use Generator;

class GeminiService implements AiClientContract
{
    private GeminiClient $client;
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
        $this->defaultModel = $model ?? config('code-talker.providers.gemini.model', 'gemini-2.5-flash');
        $this->defaultMaxTokens = $maxTokens ?? (int) config('code-talker.providers.gemini.max_tokens', 1024);

        $resolvedApiKey = $apiKey ?? '';
        $resolvedBaseUrl = rtrim(
            $baseUrl ?? config('code-talker.providers.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'),
            '/'
        );

        $factory = \Gemini::factory()
            ->withApiKey($resolvedApiKey)
            ->withBaseUrl($resolvedBaseUrl);

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
        $resolvedModel = $this->model ?? $this->defaultModel;
        $contents = $this->mapToContents($messages);

        $model = $this->client->generativeModel($resolvedModel)
            ->withGenerationConfig($this->buildGenerationConfig());

        if ($this->system !== null && $this->system !== '') {
            $model = $model->withSystemInstruction(
                new Content(parts: [new Part(text: $this->system)])
            );
        }

        $response = $model->generateContent(...$contents);

        $this->reset();

        $candidate = $response->candidates[0] ?? null;
        $parts = $candidate?->content?->parts ?? [];
        $reasoning = $this->extractThoughtText($parts);
        $text = $this->extractResponseText($parts);

        return [
            'id' => '',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
            'reasoning_content' => $reasoning !== '' ? $reasoning : null,
            'model' => $resolvedModel,
            'stop_reason' => $this->mapFinishReason($candidate?->finishReason),
            'usage' => [
                'input_tokens' => (int) ($response->usageMetadata->promptTokenCount ?? 0),
                'output_tokens' => (int) ($response->usageMetadata->candidatesTokenCount ?? 0),
            ],
        ];
    }

    public function stream(array $messages): Generator
    {
        $resolvedModel = $this->model ?? $this->defaultModel;
        $contents = $this->mapToContents($messages);

        $model = $this->client->generativeModel($resolvedModel)
            ->withGenerationConfig($this->buildGenerationConfig());

        if ($this->system !== null && $this->system !== '') {
            $model = $model->withSystemInstruction(
                new Content(parts: [new Part(text: $this->system)])
            );
        }

        $sdkStream = $model->streamGenerateContent(...$contents);

        $this->reset();

        $started = false;
        $outputTokens = null;

        foreach ($sdkStream as $chunk) {
            if (!$started) {
                $started = true;
                yield [
                    'type' => 'message_start',
                    'message' => [
                        'usage' => [
                            'input_tokens' => (int) ($chunk->usageMetadata->promptTokenCount ?? 0),
                        ],
                    ],
                ];
            }

            $candidate = $chunk->candidates[0] ?? null;
            $parts = $candidate?->content?->parts ?? [];
            $reasoning = $this->extractThoughtText($parts);
            $text = $this->extractResponseText($parts);

            if ($reasoning !== '') {
                yield [
                    'type' => 'reasoning_block_delta',
                    'delta' => ['reasoning' => $reasoning],
                ];
            }

            if ($text !== '') {
                yield [
                    'type' => 'content_block_delta',
                    'delta' => ['text' => $text],
                ];
            }

            if (isset($chunk->usageMetadata->candidatesTokenCount)) {
                $outputTokens = (int) $chunk->usageMetadata->candidatesTokenCount;
            }

            if ($candidate?->finishReason !== null) {
                yield [
                    'type' => 'message_delta',
                    'delta' => ['stop_reason' => $this->mapFinishReason($candidate->finishReason)],
                    'usage' => ['output_tokens' => $outputTokens],
                ];

                yield ['type' => 'message_stop'];
            }
        }

        if (!$started) {
            yield [
                'type' => 'message_start',
                'message' => [
                    'usage' => ['input_tokens' => 0],
                ],
            ];
        }

        yield [
            'type' => 'message_delta',
            'usage' => ['output_tokens' => $outputTokens],
        ];

        yield ['type' => 'message_stop'];
    }

    public function listModels(): array
    {
        $response = $this->client->models()->list();

        return collect($response->models)
            ->map(static function ($model): array {
                $name = $model->name;
                $modelId = str_starts_with($name, 'models/') ? substr($name, 7) : $name;

                return [
                    'id' => $modelId,
                    'display_name' => $model->displayName,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * @param array<int, array{id: string, name: string, input: array<string, mixed>}> $toolCalls
     * @return array{role: string, parts: array<int, mixed>}
     */
    public function formatAssistantToolCallTurn(string $textContent, array $toolCalls): array
    {
        $parts = [];

        if ($textContent !== '') {
            $parts[] = ['text' => $textContent];
        }

        foreach ($toolCalls as $toolCall) {
            $parts[] = [
                'functionCall' => [
                    'name' => $toolCall['name'],
                    'args' => $toolCall['input'],
                ],
            ];
        }

        return ['role' => 'model', 'parts' => $parts];
    }

    /**
     * @param array<int, array{id: string, result: array<string, mixed>}> $toolResults
     * @return array<int, array{role: string, parts: array<int, mixed>}>
     */
    public function formatToolResultTurn(array $toolResults): array
    {
        $parts = array_map(static fn (array $result): array => [
            'functionResponse' => [
                'name' => $result['name'] ?? $result['id'],
                'response' => $result['result'],
            ],
        ], $toolResults);

        return [['role' => 'user', 'parts' => $parts]];
    }

    /**
     * Map internal message format to Gemini Content objects.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, Content>
     */
    private function mapToContents(array $messages): array
    {
        return array_map(function (array $message): Content {
            // Already in Gemini API format (from formatAssistantToolCallTurn / formatToolResultTurn)
            if (isset($message['parts'])) {
                return Content::from($message);
            }

            // Standard {role, content} format
            $role = $message['role'] === 'assistant' ? Role::MODEL : Role::USER;
            $content = $message['content'];
            $text = is_string($content) ? $content : (json_encode($content, JSON_UNESCAPED_SLASHES) ?: '');

            return new Content(parts: [new Part(text: $text)], role: $role);
        }, $messages);
    }

    private function buildGenerationConfig(): GenerationConfig
    {
        return new GenerationConfig(
            maxOutputTokens: $this->maxTokens ?? $this->defaultMaxTokens,
            temperature: $this->temperature,
        );
    }

    /**
     * @param array<int, Part> $parts
     */
    private function extractResponseText(array $parts): string
    {
        return collect($parts)
            ->filter(static fn (Part $part): bool => empty($part->thought))
            ->map(static fn (Part $part): string => $part->text ?? '')
            ->implode('');
    }

    /**
     * @param array<int, Part> $parts
     */
    private function extractThoughtText(array $parts): string
    {
        return collect($parts)
            ->filter(static fn (Part $part): bool => !empty($part->thought))
            ->map(static fn (Part $part): string => $part->text ?? '')
            ->implode('');
    }

    private function mapFinishReason(?FinishReason $reason): string
    {
        return match ($reason) {
            FinishReason::MAX_TOKENS => 'max_tokens',
            default => 'end_turn',
        };
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

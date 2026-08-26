<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi;

use Generator;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\OpenAiCompatible\OpenAiCompatibleGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

/**
 * An openai-compatible text gateway that also streams reasoning.
 *
 * laravel/ai v0.9.0's OpenAiCompatible gateway drops the `reasoning_content`
 * (a.k.a. `reasoning`) field from streaming deltas — it only forwards content
 * and tool calls — so reasoning models (LM Studio / qwen3, etc.) never emit a
 * ReasoningDelta and their "thinking" no longer reaches the chat UI.
 *
 * This overrides processTextStream() to re-emit that reasoning, gated on the
 * requesting AiSystem's `enable_thinking` flag (via CodeTalkerAgent::showThinking(),
 * threaded through by the generateStreamStep() override below) so disabling the
 * flag actually stops reasoning blocks from reaching the chat UI. Both method
 * bodies are copied VERBATIM from laravel/ai v0.9.0
 * (Gateway\OpenAiCompatible\Concerns\HandlesTextStreaming::processTextStream and
 * Concerns\PerformsChatCompletionSteps::generateStreamStep) with a single added
 * branch/param each — there is no smaller seam to hook.
 * Re-check this copy whenever laravel/ai is upgraded (composer pins "^0.9").
 */
class ReasoningOpenAiCompatibleGateway extends OpenAiCompatibleGateway
{
    /**
     * Stream text for a single Chat Completions step.
     *
     * Overridden (copied verbatim from laravel/ai v0.9.0's
     * Gateway\OpenAiCompatible\Concerns\PerformsChatCompletionSteps::generateStreamStep)
     * solely to read the agent's showThinking() flag and thread it into
     * processTextStream() — there is no smaller seam to hook. Re-check this
     * copy whenever laravel/ai is upgraded (composer pins "^0.9").
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $body['stream'] = true;

        if (filled($streamOptions = $this->streamOptions($provider))) {
            $body['stream_options'] = $streamOptions;
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('chat/completions', $body),
        );

        $showThinking = $options?->agent instanceof CodeTalkerAgent
            ? $options->agent->showThinking()
            : true;

        return yield from $this->processTextStream($invocationId, $provider, $model, $response->getBody(), $showThinking);
    }

    /**
     * Process a Chat Completions streaming response for a single turn and yield Laravel stream events.
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        $streamBody,
        bool $showThinking = true,
    ): Generator {
        $messageId = $this->generateEventId();
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $toolCalls = [];
        $pendingToolCalls = [];
        $usage = null;
        $finishReason = null;
        $responseModel = $model;

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            if (isset($data['error'])) {
                yield (new Error(
                    $this->generateEventId(),
                    $data['error']['code'] ?? 'unknown_error',
                    $data['error']['message'] ?? 'Unknown error',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            $choice = $data['choices'][0] ?? null;

            if (! $choice) {
                if (isset($data['usage'])) {
                    $usage = $this->extractUsage($data);
                }

                continue;
            }

            $delta = $choice['delta'] ?? [];

            if (! $streamStartEmitted) {
                $streamStartEmitted = true;
                $responseModel = $data['model'] ?? $model;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);
            }

            // Added for code-talker: re-emit provider reasoning that the base
            // gateway drops. LM Studio streams `reasoning_content`; some other
            // openai-compatible servers use `reasoning`. StreamTranslator maps
            // ReasoningDelta -> the browser's reasoning_block_delta.
            $reasoning = $delta['reasoning_content'] ?? $delta['reasoning'] ?? null;

            if ($showThinking && is_string($reasoning) && $reasoning !== '') {
                yield (new ReasoningDelta(
                    $this->generateEventId(),
                    $messageId,
                    $reasoning,
                    time(),
                ))->withInvocationId($invocationId);
            }

            if (isset($delta['content']) && $delta['content'] !== '') {
                if (! $textStartEmitted) {
                    $textStartEmitted = true;

                    yield (new TextStart(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentText .= $delta['content'];

                yield (new TextDelta(
                    $this->generateEventId(),
                    $messageId,
                    $delta['content'],
                    time(),
                ))->withInvocationId($invocationId);
            }

            if (isset($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tcDelta) {
                    $idx = $tcDelta['index'];

                    if (! isset($pendingToolCalls[$idx])) {
                        $pendingToolCalls[$idx] = [
                            'id' => $tcDelta['id'] ?? '',
                            'name' => $tcDelta['function']['name'] ?? '',
                            'arguments' => '',
                        ];
                    }

                    if (isset($tcDelta['function']['arguments'])) {
                        $pendingToolCalls[$idx]['arguments'] .= $tcDelta['function']['arguments'];
                    }
                }
            }

            if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                $finishReason = $choice['finish_reason'];
            }

            if (isset($data['usage'])) {
                $usage = $this->extractUsage($data);
            }
        }

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if (filled($pendingToolCalls) && $finishReason === 'tool_calls') {
            $toolCalls = $this->mapStreamToolCalls($pendingToolCalls);

            foreach ($toolCalls as $toolCall) {
                yield (new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                ))->withInvocationId($invocationId);
            }
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason(['finish_reason' => $finishReason ?? '']),
            usage: $usage ?? new Usage(0, 0),
            meta: new Meta($provider->name(), $responseModel),
        );
    }
}

<?php

namespace Jvjvjv\CodeTalker\Services\LaravelAi;

use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * Translates laravel/ai stream events into the Anthropic-shaped SSE arrays the
 * browser chat UI already consumes (content_block_delta, reasoning_block_delta,
 * message_delta, message_stop, ...).
 *
 * The SDK's agentic loop can emit several StreamStart/StreamEnd pairs per
 * prompt (one per tool step); the browser turn gets exactly one message_start
 * and one terminal message_delta/message_stop pair, with usage accumulated
 * across steps. ToolCall/ToolResult events are intentionally not forwarded —
 * the browser never saw tool blocks under the previous implementation either.
 *
 * One translator instance spans one browser turn: keep it across max-token
 * continuation attempts and call finish() once, after the last attempt.
 */
class StreamTranslator
{
    private bool $messageStarted = false;

    private ?string $lastReason = null;

    private Usage $usage;

    public function __construct()
    {
        $this->usage = new Usage();
    }

    /**
     * Translate one laravel/ai stream event into zero or more browser events.
     *
     * @return array<int, array<string, mixed>>
     */
    public function translate(StreamEvent $event): array
    {
        return match (true) {
            $event instanceof StreamStart => $this->onStreamStart(),
            $event instanceof TextDelta => [[
                'type' => 'content_block_delta',
                'delta' => ['text' => $event->delta],
            ]],
            $event instanceof ReasoningDelta => [[
                'type' => 'reasoning_block_delta',
                'delta' => ['reasoning' => $event->delta],
            ]],
            $event instanceof StreamEnd => $this->onStreamEnd($event),
            $event instanceof Error => [[
                'type' => 'error',
                'message' => $event->message,
            ]],
            default => [],
        };
    }

    /**
     * The terminal browser events for the turn. Call once, after every stream
     * attempt has been drained.
     *
     * @return array<int, array<string, mixed>>
     */
    public function finish(): array
    {
        $events = $this->messageStarted ? [] : $this->onStreamStart();

        $events[] = [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => $this->stopReason()],
            'usage' => [
                'input_tokens' => $this->inputTokens() ?: null,
                'output_tokens' => $this->outputTokens() ?: null,
            ],
        ];

        $events[] = ['type' => 'message_stop'];

        return $events;
    }

    /**
     * The last finish reason mapped to the legacy Anthropic-style stop_reason.
     */
    public function stopReason(): string
    {
        return match ($this->lastReason) {
            'tool_calls' => 'tool_use',
            'length' => 'max_tokens',
            default => 'end_turn',
        };
    }

    /**
     * The last laravel/ai finish reason, unmapped (e.g. 'length', 'stop').
     */
    public function lastReason(): ?string
    {
        return $this->lastReason;
    }

    public function inputTokens(): int
    {
        return $this->usage->promptTokens;
    }

    public function outputTokens(): int
    {
        return $this->usage->completionTokens;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function onStreamStart(): array
    {
        if ($this->messageStarted) {
            return [];
        }

        $this->messageStarted = true;

        return [[
            'type' => 'message_start',
            'message' => [
                'usage' => [
                    'input_tokens' => null,
                ],
            ],
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function onStreamEnd(StreamEnd $event): array
    {
        $this->usage = $this->usage->add($event->usage);
        $this->lastReason = $event->reason;

        return [];
    }
}

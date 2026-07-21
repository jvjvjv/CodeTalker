<?php

namespace Jvjvjv\CodeTalker\Services\RawExchange;

final class ExchangeTranscriptParser
{
    /**
     * Extract just the newest user-role message from a chat-completions request
     * body — the message being sent this turn, without the system prompt or the
     * prior conversation context that is resent on every call.
     *
     * Returns the LAST message whose role is "user"; within an agentic tool loop
     * the trailing messages are assistant/tool entries, so the last user message
     * stays pinned to the turn's original prompt. Returns '' when the body is
     * missing, malformed, or carries no user message.
     */
    public function latestUserMessage(?string $requestBody): string
    {
        if ($requestBody === null || $requestBody === '') {
            return '';
        }

        $decoded = json_decode($requestBody, true);
        $messages = is_array($decoded) ? ($decoded['messages'] ?? null) : null;

        if (! is_array($messages)) {
            return '';
        }

        foreach (array_reverse($messages) as $message) {
            if (is_array($message) && ($message['role'] ?? null) === 'user') {
                return $this->stringifyContent($message['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * Parse OpenAI-compatible response bytes (streaming SSE or a single JSON body).
     *
     * @return array{text: string, reasoning: string}
     */
    public function sseResponse(?string $rawResponse): array
    {
        if ($rawResponse === null || trim($rawResponse) === '') {
            return ['text' => '', 'reasoning' => ''];
        }

        $text = '';
        $reasoning = '';
        $sawData = false;

        foreach (preg_split('/\r\n|\r|\n/', $rawResponse) as $line) {
            $line = trim((string) $line);

            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $sawData = true;
            $payload = trim(substr($line, strlen('data:')));

            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }

            $chunk = json_decode($payload, true);

            if (! is_array($chunk)) {
                continue;
            }

            [$t, $r] = $this->extractFromChoice($chunk);
            $text .= $t;
            $reasoning .= $r;
        }

        if (! $sawData) {
            $chunk = json_decode(trim($rawResponse), true);

            if (is_array($chunk)) {
                [$text, $reasoning] = $this->extractFromChoice($chunk);
            }
        }

        return ['text' => $text, 'reasoning' => $reasoning];
    }

    /**
     * Extract text/reasoning from a stored AiLlmMessage response_data payload.
     *
     * @param  array<string, mixed>|null  $responseData
     * @return array{text: string, reasoning: string}
     */
    public function llmResponse(?array $responseData): array
    {
        $text = '';
        $reasoning = '';

        $events = $responseData['events'] ?? null;

        if (is_array($events)) {
            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $delta = is_string($event['delta'] ?? null) ? $event['delta'] : '';

                if (($event['type'] ?? null) === 'text_delta') {
                    $text .= $delta;
                } elseif (($event['type'] ?? null) === 'reasoning_delta') {
                    $reasoning .= $delta;
                }
            }
        }

        return ['text' => $text, 'reasoning' => $reasoning];
    }

    /**
     * Normalize a message's `content` to a string. Content is usually a plain
     * string, but the OpenAI-compatible schema also allows an array of parts
     * (multimodal) — collect the text parts, falling back to raw JSON.
     */
    private function stringifyContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return '';
        }

        $texts = [];

        foreach ($content as $part) {
            if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                $texts[] = $part['text'];
            }
        }

        if ($texts !== []) {
            return implode("\n", $texts);
        }

        return (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $chunk
     * @return array{0: string, 1: string}
     */
    private function extractFromChoice(array $chunk): array
    {
        $choice = $chunk['choices'][0] ?? null;

        if (! is_array($choice)) {
            return ['', ''];
        }

        // Streaming chunks carry "delta"; non-streaming responses carry "message".
        $node = $choice['delta'] ?? $choice['message'] ?? [];

        if (! is_array($node)) {
            return ['', ''];
        }

        $text = is_string($node['content'] ?? null) ? $node['content'] : '';
        $reasoning = is_string($node['reasoning_content'] ?? null) ? $node['reasoning_content'] : '';

        return [$text, $reasoning];
    }
}

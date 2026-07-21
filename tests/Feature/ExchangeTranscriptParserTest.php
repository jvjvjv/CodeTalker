<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Services\RawExchange\ExchangeTranscriptParser;
use Jvjvjv\CodeTalker\Tests\TestCase;

class ExchangeTranscriptParserTest extends TestCase
{
    private function parser(): ExchangeTranscriptParser
    {
        return new ExchangeTranscriptParser();
    }

    public function test_latest_user_message_returns_the_last_user_message_only(): void
    {
        $body = json_encode([
            'model' => 'qwen',
            'messages' => [
                ['role' => 'system', 'content' => 'You are helpful'],
                ['role' => 'user', 'content' => 'First question'],
                ['role' => 'assistant', 'content' => 'An answer'],
                ['role' => 'user', 'content' => 'Follow-up question'],
            ],
        ]);

        $this->assertSame('Follow-up question', $this->parser()->latestUserMessage($body));
    }

    public function test_latest_user_message_ignores_trailing_tool_and_assistant_messages(): void
    {
        $body = json_encode([
            'messages' => [
                ['role' => 'user', 'content' => 'Look up the weather'],
                ['role' => 'assistant', 'content' => ''],
                ['role' => 'tool', 'content' => '{"temp":72}'],
            ],
        ]);

        $this->assertSame('Look up the weather', $this->parser()->latestUserMessage($body));
    }

    public function test_latest_user_message_extracts_text_parts_from_multimodal_content(): void
    {
        $body = json_encode([
            'messages' => [
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'What is in this image?'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:...']],
                ]],
            ],
        ]);

        $this->assertSame('What is in this image?', $this->parser()->latestUserMessage($body));
    }

    public function test_latest_user_message_returns_empty_when_no_user_message(): void
    {
        $body = json_encode([
            'messages' => [
                ['role' => 'system', 'content' => 'You are helpful'],
            ],
        ]);

        $this->assertSame('', $this->parser()->latestUserMessage($body));
    }

    public function test_latest_user_message_handles_null_and_malformed(): void
    {
        $this->assertSame('', $this->parser()->latestUserMessage(null));
        $this->assertSame('', $this->parser()->latestUserMessage('not json'));
        $this->assertSame('', $this->parser()->latestUserMessage('{"model":"qwen"}'));
    }

    public function test_sse_response_concatenates_streaming_content_and_reasoning(): void
    {
        $raw = "data: {\"choices\":[{\"delta\":{\"content\":\"Streamed \"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"reasoning_content\":\"thinking\"}}]}\n\n"
            . "data: [DONE]\n\n";

        $result = $this->parser()->sseResponse($raw);

        $this->assertSame('Streamed hi', $result['text']);
        $this->assertSame('thinking', $result['reasoning']);
    }

    public function test_sse_response_parses_non_streaming_json_body(): void
    {
        $raw = json_encode([
            'choices' => [['message' => ['content' => 'Final answer']]],
        ]);

        $result = $this->parser()->sseResponse($raw);

        $this->assertSame('Final answer', $result['text']);
        $this->assertSame('', $result['reasoning']);
    }

    public function test_sse_response_handles_null(): void
    {
        $this->assertSame(['text' => '', 'reasoning' => ''], $this->parser()->sseResponse(null));
    }

    public function test_llm_response_extracts_text_and_reasoning_deltas(): void
    {
        $data = [
            'events' => [
                ['type' => 'text_delta', 'delta' => 'Hello '],
                ['type' => 'text_delta', 'delta' => 'there'],
                ['type' => 'reasoning_delta', 'delta' => 'hmm'],
                ['type' => 'stream_end'],
            ],
        ];

        $result = $this->parser()->llmResponse($data);

        $this->assertSame('Hello there', $result['text']);
        $this->assertSame('hmm', $result['reasoning']);
    }

    public function test_llm_response_handles_null(): void
    {
        $this->assertSame(['text' => '', 'reasoning' => ''], $this->parser()->llmResponse(null));
    }
}

<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Services\Mcp\ToolResultConverter;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\FetchWebPageTool;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Laravel\Mcp\Request;

class FetchWebPageToolTest extends TestCase
{
    /**
     * Run the tool the way the local chat loop does and return the normalized array.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runTool(FetchWebPageTool $tool, array $input): array
    {
        return ToolResultConverter::toArray($tool->handle(new Request($input)));
    }

    public function testItFetchesHtmlContentWithTheJayScraperUserAgent(): void
    {
        Http::fake([
            'https://example.com/article' => Http::response(
                '<html><head><title>Example Article</title></head><body><main><h1>Heading</h1><p>First paragraph.</p><script>alert("x")</script><p>Second paragraph.</p></main></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $conversation = new AiConversation(['context' => []]);
        $conversation->setRelation('aiChatBot', new AiChatBot(['name' => 'Research Bot']));

        $tool = new FetchWebPageTool(ToolContext::forConversation($conversation));

        $result = $this->runTool($tool, ['url' => 'https://example.com/article']);

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === 'https://example.com/article'
                && $request->hasHeader('User-Agent', 'JayScraper/0.2.0 (name: Research Bot; purpose: research; contact: https://jasonvertucio.com)');
        });

        $this->assertSame('https://example.com/article', $result['url']);
        $this->assertSame('Example Article', $result['title']);
        $this->assertStringContainsString('Heading', $result['content']);
        $this->assertStringContainsString('First paragraph.', $result['content']);
        $this->assertStringContainsString('Second paragraph.', $result['content']);
        $this->assertStringNotContainsString('alert("x")', $result['content']);
        $this->assertFalse($result['truncated']);
    }

    public function testItRejectsUnsupportedUrlSchemes(): void
    {
        Http::fake();

        $tool = new FetchWebPageTool(new ToolContext());

        $result = $this->runTool($tool, ['url' => 'file:///etc/passwd']);

        $this->assertSame('The URL must be a valid http or https address.', $result['error']);
        Http::assertNothingSent();
    }

    public function testItReturnsAMeaningfulErrorForHttpErrorResponses(): void
    {
        Http::fake([
            'https://example.com/missing' => Http::response(
                '<!DOCTYPE html><html><body>Not Found</body></html>',
                404,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $tool = new FetchWebPageTool(new ToolContext());

        $result = $this->runTool($tool, ['url' => 'https://example.com/missing']);

        $this->assertSame(
            'Failed to fetch https://example.com/missing. The server responded with HTTP status 404 (Not Found).',
            $result['error'],
        );
        $this->assertArrayNotHasKey('content', $result);
    }

    public function testItReturnsAMeaningfulErrorWhenTheConnectionFails(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('cURL error 6: Could not resolve host: example.invalid');
        });

        $tool = new FetchWebPageTool(new ToolContext());

        $result = $this->runTool($tool, ['url' => 'https://example.invalid/page']);

        $this->assertSame(
            'Could not connect to https://example.invalid/page. The request failed before receiving a response.',
            $result['error'],
        );
    }

    public function testItTruncatesLongContentByDefault(): void
    {
        $longParagraph = str_repeat('a', 25000);

        Http::fake([
            'https://example.com/long' => Http::response(
                '<html><head><title>Long</title></head><body><p>' . $longParagraph . '</p></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $tool = new FetchWebPageTool(new ToolContext());

        $result = $this->runTool($tool, ['url' => 'https://example.com/long']);

        $this->assertTrue($result['truncated']);
        $this->assertLessThan(25000, mb_strlen($result['content']));
    }

    public function testItSkipsTruncationWhenTruncateContentIsFalse(): void
    {
        $longParagraph = str_repeat('a', 25000);

        Http::fake([
            'https://example.com/long' => Http::response(
                '<html><head><title>Long</title></head><body><p>' . $longParagraph . '</p></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $tool = new FetchWebPageTool(new ToolContext());

        $result = $this->runTool($tool, [
            'url' => 'https://example.com/long',
            'truncate_content' => false,
        ]);

        $this->assertFalse($result['truncated']);
        $this->assertSame(25000, mb_strlen($result['content']));
    }

    public function testItHandlesPlainTextResponses(): void
    {
        Http::fake([
            'https://example.com/plain' => Http::response(
                "Line one\n\nLine two",
                200,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            ),
        ]);

        $tool = new FetchWebPageTool(new ToolContext());

        $result = $this->runTool($tool, ['url' => 'https://example.com/plain']);

        $this->assertNull($result['title']);
        $this->assertSame("Line one\n\nLine two", $result['content']);
        $this->assertFalse($result['truncated']);
    }
}

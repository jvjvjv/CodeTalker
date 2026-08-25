<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Services\Mcp\ToolResultConverter;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\FetchWebPageTool;
use Jvjvjv\CodeTalker\Services\Web\HostGate;
use Jvjvjv\CodeTalker\Services\Web\WebFetcher;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Jvjvjv\CodeTalker\Support\WebScraperUserAgent;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Laravel\Mcp\Request;

class FetchWebPageToolTest extends TestCase
{
    /**
     * A tool whose host resolution is fixed, so no test touches DNS.
     *
     * Hosts not listed resolve to a public address; the private cases are
     * declared explicitly.
     */
    private function tool(?ToolContext $context = null): FetchWebPageTool
    {
        return new class($context ?? new ToolContext()) extends FetchWebPageTool {
            protected function fetcher(): WebFetcher
            {
                $gate = new class('This page was not fetched. The host "%s" resolves to a private, loopback, '
                    . 'or link-local address. Declare request_policy.allow_private_hosts as true if reaching an '
                    . 'internal address is genuinely what you intend.') extends HostGate {
                    /** @return array<int, string> */
                    protected function addressesFor(string $host): array
                    {
                        return match ($host) {
                            'localhost', '127.0.0.1' => ['127.0.0.1'],
                            'internal.test' => ['10.0.0.5'],
                            'metadata.test' => ['169.254.169.254'],
                            default => ['93.184.216.34'],
                        };
                    }
                };

                return new WebFetcher($gate, $this->context->botName(), 'fetch-web-page');
            }
        };
    }

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

    public function testItFetchesHtmlContentWithTheSharedScraperUserAgent(): void
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

        $tool = $this->tool(ToolContext::forConversation($conversation));

        $result = $this->runTool($tool, ['url' => 'https://example.com/article']);

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === 'https://example.com/article'
                && $request->hasHeader('User-Agent', WebScraperUserAgent::forBotName('Research Bot'));
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

        $tool = $this->tool();

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

        $tool = $this->tool();

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

        $tool = $this->tool();

        $result = $this->runTool($tool, ['url' => 'https://unreachable.example.com/page']);

        $this->assertSame(
            'Could not connect to https://unreachable.example.com/page. The request failed before receiving a response.',
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

        $tool = $this->tool();

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

        $tool = $this->tool();

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

        $tool = $this->tool();

        $result = $this->runTool($tool, ['url' => 'https://example.com/plain']);

        $this->assertNull($result['title']);
        $this->assertSame("Line one\n\nLine two", $result['content']);
        $this->assertFalse($result['truncated']);
    }

    // ------------------------------------------------------------- host gate

    public function testItFetchesAPublicPageWithNoPolicyDeclared(): void
    {
        Http::fake(['https://example.com/*' => Http::response('<html><body><p>Public.</p></body></html>', 200, ['Content-Type' => 'text/html'])]);

        $result = $this->runTool($this->tool(), ['url' => 'https://example.com/page']);

        $this->assertStringContainsString('Public.', $result['content']);
    }

    public function testItRefusesPrivateHostsWhenNoPolicyIsDeclared(): void
    {
        foreach (['http://127.0.0.1/', 'http://internal.test/admin', 'http://metadata.test/latest'] as $url) {
            Http::fake();

            $result = $this->runTool($this->tool(), ['url' => $url]);

            $this->assertStringContainsString('allow_private_hosts', $result['error'], $url . ' should be refused');
            Http::assertNothingSent();
        }
    }

    public function testTheRefusalNamesOnlyInputsThisToolActuallyHas(): void
    {
        Http::fake();

        $result = $this->runTool($this->tool(), ['url' => 'http://127.0.0.1/']);

        // fetch-web-page is GET-only and has no allowed_methods input; pointing
        // the model at one would be an instruction it cannot follow.
        $this->assertStringNotContainsString('allowed_methods', $result['error']);
        $this->assertStringContainsString('request_policy.allow_private_hosts', $result['error']);
    }

    public function testItFetchesAPrivateHostWhenExplicitlyDeclared(): void
    {
        Http::fake(['http://127.0.0.1/*' => Http::response('<html><body><p>Internal.</p></body></html>', 200, ['Content-Type' => 'text/html'])]);

        $result = $this->runTool($this->tool(), [
            'url' => 'http://127.0.0.1/status',
            'request_policy' => ['allow_private_hosts' => true],
        ]);

        $this->assertStringContainsString('Internal.', $result['content']);
    }

    public function testItRefusesAHostOutsideADeclaredAllowList(): void
    {
        Http::fake();

        $result = $this->runTool($this->tool(), [
            'url' => 'https://other.example.com/page',
            'request_policy' => ['allowed_hosts' => ['example.com']],
        ]);

        $this->assertStringContainsString('other.example.com', $result['error']);
        $this->assertStringContainsString('allowed_hosts', $result['error']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- redirects

    public function testARedirectIntoAPrivateNetworkIsRefused(): void
    {
        Http::fake([
            'https://example.com/redirect' => Http::response('', 302, ['Location' => 'http://metadata.test/latest/meta-data/']),
            'http://metadata.test/*' => Http::response('secret', 200, ['Content-Type' => 'text/plain']),
        ]);

        $result = $this->runTool($this->tool(), ['url' => 'https://example.com/redirect']);

        $this->assertStringContainsString('redirected', $result['error']);
        $this->assertStringContainsString('allow_private_hosts', $result['error']);
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'metadata.test'));
    }

    public function testAPermittedRedirectIsFollowedAndReportsTheFinalUrl(): void
    {
        Http::fake([
            'https://example.com/redirect' => Http::response('', 302, ['Location' => '/article']),
            'https://example.com/article' => Http::response('<html><head><title>Moved</title></head><body><p>Arrived.</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->runTool($this->tool(), ['url' => 'https://example.com/redirect']);

        $this->assertStringContainsString('Arrived.', $result['content']);
        $this->assertSame('https://example.com/article', $result['url']);
        $this->assertSame('Moved', $result['title']);
    }

    // --------------------------------------------------------- address pinning

    public function testItPinsTheValidatedAddressIntoTheConnection(): void
    {
        $captured = [];

        // Fake callbacks receive the Guzzle options, which is where the pin lands.
        Http::fake(function (HttpRequest $request, array $options) use (&$captured) {
            $captured[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

            return Http::response('<html><body><p>Hi</p></body></html>', 200, ['Content-Type' => 'text/html']);
        });

        $this->runTool($this->tool(), ['url' => 'https://example.com/page']);

        // Without this the gate and the socket resolve independently, and a host
        // answering differently between the two walks straight through the check.
        $this->assertSame([['example.com:443:93.184.216.34']], $captured);
    }

    public function testEachRedirectHopPinsItsOwnAddress(): void
    {
        $captured = [];

        Http::fake(function (HttpRequest $request, array $options) use (&$captured) {
            $captured[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

            return str_contains($request->url(), '/redirect')
                ? Http::response('', 302, ['Location' => 'https://other.example.com/article'])
                : Http::response('<html><body><p>Arrived.</p></body></html>', 200, ['Content-Type' => 'text/html']);
        });

        $this->runTool($this->tool(), ['url' => 'https://example.com/redirect']);

        $this->assertSame(
            [['example.com:443:93.184.216.34'], ['other.example.com:443:93.184.216.34']],
            $captured,
        );
    }

    public function testAnIpLiteralHostNeedsNoPin(): void
    {
        $captured = [];

        Http::fake(function (HttpRequest $request, array $options) use (&$captured) {
            $captured[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

            return Http::response('<html><body><p>Hi</p></body></html>', 200, ['Content-Type' => 'text/html']);
        });

        $this->runTool($this->tool(), [
            'url' => 'http://127.0.0.1/status',
            'request_policy' => ['allow_private_hosts' => true],
        ]);

        $this->assertSame([null], $captured);
    }
}

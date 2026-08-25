<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Services\Mcp\ToolResultConverter;
use Jvjvjv\CodeTalker\Services\Mcp\Tools\ChatBot\HttpRequestTool;
use Jvjvjv\CodeTalker\Support\ToolContext;
use Jvjvjv\CodeTalker\Support\WebScraperUserAgent;
use Jvjvjv\CodeTalker\Tests\TestCase;
use Laravel\Mcp\Request;

class HttpRequestToolTest extends TestCase
{
    /**
     * A tool whose host resolution is fixed, so no test touches DNS.
     *
     * Hosts not listed resolve to a public address; the private cases are
     * declared explicitly.
     */
    private function tool(?string $botName = null): HttpRequestTool
    {
        $context = new ToolContext();

        if ($botName !== null) {
            $conversation = new AiConversation(['context' => []]);
            $conversation->setRelation('aiChatBot', new AiChatBot(['name' => $botName]));
            $context = ToolContext::forConversation($conversation);
        }

        return new class($context) extends HttpRequestTool {
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
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runTool(array $input, ?string $botName = null): array
    {
        return ToolResultConverter::toArray($this->tool($botName)->handle(new Request($input)));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'url' => 'https://api.example.com/v1/things',
            'method' => 'GET',
            'request_policy' => ['allowed_methods' => ['GET']],
        ], $overrides);
    }

    // ---------------------------------------------------------------- requests

    public function testItReturnsDecodedJsonRatherThanAJsonString(): void
    {
        Http::fake([
            'https://api.example.com/v1/things' => Http::response(
                '{"things":[{"id":1,"name":"first"}],"total":1}',
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $result = $this->runTool($this->input());

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('application/json', $result['content_type']);
        $this->assertIsArray($result['content']);
        $this->assertSame(1, $result['content']['total']);
        $this->assertSame('first', $result['content']['things'][0]['name']);
        $this->assertFalse($result['truncated']);
    }

    public function testItSendsAPostWithTheGivenBody(): void
    {
        Http::fake([
            'https://api.example.com/v1/things' => Http::response('{"ok":true}', 201, ['Content-Type' => 'application/json']),
        ]);

        $result = $this->runTool($this->input([
            'method' => 'POST',
            'body' => '{"name":"second"}',
            'headers' => ['Content-Type' => 'application/json'],
            'request_policy' => ['allowed_methods' => ['GET', 'POST']],
        ]));

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->method() === 'POST'
                && $request->body() === '{"name":"second"}'
                && $request->hasHeader('Content-Type', 'application/json');
        });

        $this->assertSame(201, $result['status']);
        $this->assertTrue($result['content']['ok']);
    }

    public function testItIdentifiesItselfWithTheScraperUserAgentWithoutAConversation(): void
    {
        Http::fake(['https://api.example.com/*' => Http::response('{}', 200, ['Content-Type' => 'application/json'])]);

        $this->runTool($this->input());

        Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader(
            'User-Agent',
            WebScraperUserAgent::forBotName(null),
        ));
    }

    // ----------------------------------------------------------- policy gate

    public function testItRefusesARequestWithNoDeclaredPolicy(): void
    {
        Http::fake();

        $result = $this->runTool(['url' => 'https://api.example.com/v1/things', 'method' => 'GET']);

        $this->assertStringContainsString('request_policy.allowed_methods', $result['error']);
        Http::assertNothingSent();
    }

    public function testItRefusesAPolicyDeclaringNoMethods(): void
    {
        Http::fake();

        $result = $this->runTool($this->input(['request_policy' => ['allowed_methods' => []]]));

        $this->assertStringContainsString('request_policy.allowed_methods', $result['error']);
        Http::assertNothingSent();
    }

    public function testItRefusesAMethodOutsideTheDeclaredPolicy(): void
    {
        Http::fake();

        $result = $this->runTool($this->input([
            'method' => 'DELETE',
            'request_policy' => ['allowed_methods' => ['GET']],
        ]));

        $this->assertStringContainsString('DELETE', $result['error']);
        $this->assertStringContainsString('allows only: GET', $result['error']);
        Http::assertNothingSent();
    }

    public function testItAllowsAMethodInsideTheDeclaredPolicy(): void
    {
        Http::fake(['https://api.example.com/*' => Http::response('', 204, ['Content-Type' => 'application/json'])]);

        $this->runTool($this->input([
            'method' => 'DELETE',
            'request_policy' => ['allowed_methods' => ['GET', 'DELETE']],
        ]));

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE');
    }

    public function testItRefusesPrivateAndLoopbackHostsWhenNotDeclared(): void
    {
        foreach (['http://127.0.0.1:8000/internal', 'http://internal.test/admin', 'http://metadata.test/latest'] as $url) {
            Http::fake();

            $result = $this->runTool($this->input(['url' => $url]));

            $this->assertStringContainsString('allow_private_hosts', $result['error'], $url . ' should be refused');
            Http::assertNothingSent();
        }
    }

    public function testItAllowsAPrivateHostWhenTheDeclaredPolicyPermitsIt(): void
    {
        Http::fake(['http://127.0.0.1:8000/*' => Http::response('{"ok":true}', 200, ['Content-Type' => 'application/json'])]);

        $result = $this->runTool($this->input([
            'url' => 'http://127.0.0.1:8000/internal',
            'request_policy' => ['allowed_methods' => ['GET'], 'allow_private_hosts' => true],
        ]));

        $this->assertTrue($result['content']['ok']);
        Http::assertSentCount(1);
    }

    public function testItRefusesAHostOutsideTheDeclaredAllowList(): void
    {
        Http::fake();

        $result = $this->runTool($this->input([
            'url' => 'https://other.example.com/v1/things',
            'request_policy' => ['allowed_methods' => ['GET'], 'allowed_hosts' => ['api.example.com']],
        ]));

        $this->assertStringContainsString('other.example.com', $result['error']);
        $this->assertStringContainsString('allowed_hosts', $result['error']);
        Http::assertNothingSent();
    }

    public function testItRefusesNonHttpSchemesRegardlessOfPolicy(): void
    {
        foreach (['file:///etc/passwd', 'ftp://example.com/f', 'gopher://example.com/'] as $url) {
            Http::fake();

            $result = $this->runTool([
                'url' => $url,
                'method' => 'GET',
                'request_policy' => [
                    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                    'allow_private_hosts' => true,
                ],
            ]);

            $this->assertSame('The URL must be a valid http or https address.', $result['error']);
            Http::assertNothingSent();
        }
    }

    // -------------------------------------------------------- header policy

    public function testItStripsAuthenticationHeadersAndReportsThem(): void
    {
        Http::fake(['https://api.example.com/*' => Http::response('{}', 200, ['Content-Type' => 'application/json'])]);

        $result = $this->runTool($this->input([
            'headers' => ['Authorization' => 'Bearer model-invented-token', 'Cookie' => 'session=1'],
        ]));

        Http::assertSent(fn (HttpRequest $request): bool => !$request->hasHeader('Authorization') && !$request->hasHeader('Cookie'));

        $this->assertContains('Authorization', $result['stripped_headers']);
        $this->assertContains('Cookie', $result['stripped_headers']);
    }

    public function testItPassesOrdinaryHeadersThrough(): void
    {
        Http::fake(['https://api.example.com/*' => Http::response('{}', 200, ['Content-Type' => 'application/json'])]);

        $result = $this->runTool($this->input([
            'headers' => ['X-Request-Id' => 'abc-123', 'Accept' => 'application/json'],
        ]));

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->hasHeader('X-Request-Id', 'abc-123')
                && $request->hasHeader('Accept', 'application/json');
        });

        $this->assertArrayNotHasKey('stripped_headers', $result);
    }

    public function testTheCallerCannotOverrideTheUserAgent(): void
    {
        Http::fake(['https://api.example.com/*' => Http::response('{}', 200, ['Content-Type' => 'application/json'])]);

        $this->runTool($this->input(['headers' => ['User-Agent' => 'definitely-a-real-browser']]), 'Research Bot');

        Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader(
            'User-Agent',
            WebScraperUserAgent::forBotName('Research Bot'),
        ));
    }

    // ---------------------------------------------------------- credentials

    public function testItAttachesHostConfiguredCredentialsAndNeverEchoesThem(): void
    {
        config(['code-talker.tools.http_request.credentials' => [
            'api.example.com' => ['Authorization' => 'Bearer host-configured-secret'],
        ]]);

        Http::fake(['https://api.example.com/*' => Http::response('{"ok":true}', 200, ['Content-Type' => 'application/json'])]);

        $result = $this->runTool($this->input(['headers' => ['Authorization' => 'Bearer model-invented-token']]));

        Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader(
            'Authorization',
            'Bearer host-configured-secret',
        ));

        $this->assertStringNotContainsString('host-configured-secret', json_encode($result) ?: '');
    }

    public function testAnUnconfiguredHostReceivesNoCredentials(): void
    {
        config(['code-talker.tools.http_request.credentials' => [
            'api.example.com' => ['Authorization' => 'Bearer host-configured-secret'],
        ]]);

        Http::fake(['https://other.example.com/*' => Http::response('{}', 200, ['Content-Type' => 'application/json'])]);

        $this->runTool($this->input(['url' => 'https://other.example.com/v1/things']));

        Http::assertSent(fn (HttpRequest $request): bool => !$request->hasHeader('Authorization'));
    }

    // ------------------------------------------------------------- decoding

    public function testItDecodesXmlWithoutResolvingExternalEntities(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0"?>
        <!DOCTYPE feed [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
        <feed><entry><title>First</title></entry><secret>&xxe;</secret></feed>
        XML;

        Http::fake(['https://api.example.com/*' => Http::response($xml, 200, ['Content-Type' => 'application/xml'])]);

        $result = $this->runTool($this->input());

        $this->assertIsArray($result['content']);
        $this->assertSame('First', $result['content']['entry']['title']);
        $this->assertStringNotContainsString('root:', json_encode($result) ?: '');
    }

    public function testItExtractsHtmlThroughTheTargetSelector(): void
    {
        Http::fake([
            'https://api.example.com/*' => Http::response(
                '<html><head><title>Doc</title></head><body><nav>Skip me</nav><main><p>Keep me.</p></main></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $result = $this->runTool($this->input(['target_selector' => 'main']));

        $this->assertStringContainsString('Keep me.', $result['content']);
        $this->assertStringNotContainsString('Skip me', $result['content']);
        $this->assertSame('Doc', $result['title']);
    }

    public function testItReportsAnUnmatchedTargetSelector(): void
    {
        Http::fake([
            'https://api.example.com/*' => Http::response('<html><body><p>Hi</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->runTool($this->input(['target_selector' => '#nope']));

        $this->assertSame('No elements matched target_selector "#nope".', $result['error']);
    }

    public function testMalformedJsonFallsBackToTextWithANote(): void
    {
        Http::fake(['https://api.example.com/*' => Http::response('{"broken": ', 200, ['Content-Type' => 'application/json'])]);

        $result = $this->runTool($this->input());

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('{"broken":', $result['content']);
        $this->assertStringContainsString('could not be parsed', $result['notes'][0]);
    }

    public function testItRefusesBinaryContentTypes(): void
    {
        $binaryTypes = ['image/png', 'application/pdf', 'application/octet-stream'];

        // One fake per path: Http::fake() merges stubs, so re-faking the same
        // URL pattern inside a loop would keep matching the first stub.
        Http::fake(collect($binaryTypes)->mapWithKeys(fn (string $type, int $index): array => [
            'https://api.example.com/binary-' . $index => Http::response('binary-bytes', 200, ['Content-Type' => $type]),
        ])->all());

        foreach ($binaryTypes as $index => $contentType) {
            $result = $this->runTool($this->input(['url' => 'https://api.example.com/binary-' . $index]));

            $this->assertStringContainsString($contentType, $result['error']);
            $this->assertArrayNotHasKey('content', $result);
        }
    }

    public function testItReadsOtherTextContentTypes(): void
    {
        Http::fake(['https://api.example.com/*' => Http::response("a,b\n1,2", 200, ['Content-Type' => 'text/csv'])]);

        $result = $this->runTool($this->input());

        $this->assertSame("a,b\n1,2", $result['content']);
    }

    // ------------------------------------------------------------ redirects

    public function testARedirectIntoAPrivateNetworkIsRefused(): void
    {
        // The bypass this guards: the gate only ever sees the public first hop.
        // Guzzle follows up to five redirects by default, so without per-hop
        // validation a public URL can bounce the request onto link-local metadata.
        Http::fake([
            'https://api.example.com/redirect' => Http::response('', 302, ['Location' => 'http://metadata.test/latest/meta-data/']),
            'http://metadata.test/*' => Http::response('secret-instance-credentials', 200, ['Content-Type' => 'text/plain']),
        ]);

        $result = $this->runTool($this->input(['url' => 'https://api.example.com/redirect']));

        $this->assertStringContainsString('redirected', $result['error']);
        $this->assertStringContainsString('allow_private_hosts', $result['error']);
        $this->assertArrayNotHasKey('content', $result);

        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'metadata.test'));
    }

    public function testARedirectToALoopbackHostIsRefused(): void
    {
        Http::fake([
            'https://api.example.com/redirect' => Http::response('', 301, ['Location' => 'http://127.0.0.1:8000/internal']),
            'http://127.0.0.1:8000/*' => Http::response('{"internal":true}', 200, ['Content-Type' => 'application/json']),
        ]);

        $result = $this->runTool($this->input(['url' => 'https://api.example.com/redirect']));

        $this->assertStringContainsString('redirected', $result['error']);
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '127.0.0.1'));
    }

    public function testARedirectOutsideTheDeclaredHostAllowListIsRefused(): void
    {
        Http::fake([
            'https://api.example.com/redirect' => Http::response('', 302, ['Location' => 'https://other.example.com/things']),
            'https://other.example.com/*' => Http::response('{}', 200, ['Content-Type' => 'application/json']),
        ]);

        $result = $this->runTool($this->input([
            'url' => 'https://api.example.com/redirect',
            'request_policy' => ['allowed_methods' => ['GET'], 'allowed_hosts' => ['api.example.com']],
        ]));

        $this->assertStringContainsString('redirected', $result['error']);
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'other.example.com'));
    }

    public function testAPermittedRedirectIsFollowedToItsDestination(): void
    {
        Http::fake([
            'https://api.example.com/redirect' => Http::response('', 302, ['Location' => '/v1/things']),
            'https://api.example.com/v1/things' => Http::response('{"ok":true}', 200, ['Content-Type' => 'application/json']),
        ]);

        $result = $this->runTool($this->input(['url' => 'https://api.example.com/redirect']));

        $this->assertTrue($result['content']['ok']);
        $this->assertSame('https://api.example.com/v1/things', $result['url']);
    }

    public function testARedirectDoesNotCarryTheFirstHostsCredentials(): void
    {
        config(['code-talker.tools.http_request.credentials' => [
            'api.example.com' => ['Authorization' => 'Bearer first-host-secret'],
        ]]);

        Http::fake([
            'https://api.example.com/redirect' => Http::response('', 302, ['Location' => 'https://other.example.com/things']),
            'https://other.example.com/*' => Http::response('{"ok":true}', 200, ['Content-Type' => 'application/json']),
        ]);

        $this->runTool($this->input([
            'url' => 'https://api.example.com/redirect',
            'request_policy' => ['allowed_methods' => ['GET']],
        ]));

        Http::assertSent(function (HttpRequest $request): bool {
            return !str_contains($request->url(), 'other.example.com') || !$request->hasHeader('Authorization');
        });
    }

    public function testARedirectLoopIsAbandonedRatherThanFollowedForever(): void
    {
        Http::fake([
            'https://api.example.com/loop' => Http::response('', 302, ['Location' => 'https://api.example.com/loop']),
        ]);

        $result = $this->runTool($this->input(['url' => 'https://api.example.com/loop']));

        $this->assertStringContainsString('redirects', $result['error']);
    }

    // ------------------------------------------------------ caps and errors

    public function testItTruncatesOversizedTextAndFlagsIt(): void
    {
        Http::fake(['https://api.example.com/*' => Http::response(str_repeat('a', 25000), 200, ['Content-Type' => 'text/plain'])]);

        $result = $this->runTool($this->input());

        $this->assertTrue($result['truncated']);
        $this->assertSame(20000, mb_strlen($result['content']));
    }

    public function testAnOversizedStructureIsDowngradedToTextRatherThanBrokenJson(): void
    {
        $large = json_encode(['items' => array_fill(0, 2000, ['name' => str_repeat('x', 40)])]);

        Http::fake(['https://api.example.com/*' => Http::response($large, 200, ['Content-Type' => 'application/json'])]);

        $result = $this->runTool($this->input());

        $this->assertTrue($result['truncated']);
        $this->assertIsString($result['content']);
        $this->assertSame(20000, mb_strlen($result['content']));
        $this->assertStringContainsString('exceeded the content limit', $result['notes'][0]);
    }

    public function testItReturnsTheWholeStructureWhenTruncationIsDeclined(): void
    {
        $large = json_encode(['items' => array_fill(0, 2000, ['name' => str_repeat('x', 40)])]);

        Http::fake(['https://api.example.com/*' => Http::response($large, 200, ['Content-Type' => 'application/json'])]);

        $result = $this->runTool($this->input(['truncate_content' => false]));

        $this->assertFalse($result['truncated']);
        $this->assertIsArray($result['content']);
        $this->assertCount(2000, $result['content']['items']);
    }

    public function testItReportsAConnectionFailure(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('cURL error 6: Could not resolve host');
        });

        $result = $this->runTool($this->input(['url' => 'https://api.example.com/down']));

        $this->assertSame(
            'Could not connect to https://api.example.com/down. The request failed before receiving a response.',
            $result['error'],
        );
    }

    public function testItReportsANonSuccessStatus(): void
    {
        Http::fake(['https://api.example.com/*' => Http::response('{"error":"nope"}', 404, ['Content-Type' => 'application/json'])]);

        $result = $this->runTool($this->input(['url' => 'https://api.example.com/missing']));

        $this->assertStringContainsString('https://api.example.com/missing', $result['error']);
        $this->assertStringContainsString('404', $result['error']);
    }
}

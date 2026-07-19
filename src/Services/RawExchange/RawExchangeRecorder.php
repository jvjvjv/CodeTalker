<?php

namespace Jvjvjv\CodeTalker\Services\RawExchange;

use Closure;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jvjvjv\CodeTalker\Models\AiProviderExchange;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class RawExchangeRecorder
{
    public function __construct(
        private RawExchangeContext $context,
    ) {
    }

    /**
     * Register the global Http middleware once. Safe to call in provider boot.
     */
    public function register(): void
    {
        Http::globalMiddleware($this->middleware());
    }

    /**
     * Run a callback with a capture frame active, popping it afterward.
     */
    public function capture(RawExchangeFrame $frame, Closure $callback): mixed
    {
        $this->context->push($frame);

        try {
            return $callback();
        } finally {
            $this->context->pop();
        }
    }

    public function middleware(): Closure
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $frame = $this->shouldCapture($request);

                if ($frame === null) {
                    return $handler($request, $options);
                }

                $method = $request->getMethod();
                $endpoint = $request->getUri()->getPath();
                $requestBody = (string) $request->getBody();

                if ($request->getBody()->isSeekable()) {
                    $request->getBody()->rewind();
                }

                $streaming = (bool) ($options['stream'] ?? false);
                $startedAt = microtime(true);

                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($frame, $method, $endpoint, $requestBody, $streaming, $startedAt) {
                        $status = $response->getStatusCode();

                        if ($streaming) {
                            $teed = new TeeingStream(
                                $response->getBody(),
                                fn (string $bytes) => $this->write($frame, $method, $endpoint, $requestBody, true, $status, $bytes, $startedAt),
                            );

                            return $response->withBody($teed);
                        }

                        $bytes = (string) $response->getBody();

                        $this->write($frame, $method, $endpoint, $requestBody, false, $status, $bytes, $startedAt);

                        return $response->withBody(Utils::streamFor($bytes));
                    }
                );
            };
        };
    }

    private function shouldCapture(RequestInterface $request): ?RawExchangeFrame
    {
        if (! config('code-talker.raw_exchanges.enabled', true)) {
            return null;
        }

        $frame = $this->context->current();

        if ($frame === null) {
            return null;
        }

        if (! $this->providerAllowed($frame->provider)) {
            return null;
        }

        if (! $this->hostMatches($frame, $request)) {
            return null;
        }

        return $frame;
    }

    private function providerAllowed(string $provider): bool
    {
        $list = $this->normalizeProviders(config('code-talker.raw_exchanges.providers', 'lm-studio'));

        return $list === null || in_array($provider, $list, true);
    }

    /**
     * @return array<int, string>|null  null means "all providers"; an empty array means "no providers"
     */
    private function normalizeProviders(mixed $configured): ?array
    {
        $items = is_array($configured) ? $configured : explode(',', (string) $configured);
        $items = array_values(array_filter(array_map('trim', $items), static fn ($v) => $v !== ''));

        // Only the explicit "all" token opts in to every provider. An empty
        // setting returns an empty allow-list (capture nothing), so clearing
        // the config narrows capture rather than silently widening it.
        if (in_array('all', array_map('strtolower', $items), true)) {
            return null;
        }

        return $items;
    }

    private function hostMatches(RawExchangeFrame $frame, RequestInterface $request): bool
    {
        $frameHost = $frame->host();

        if ($frameHost === null) {
            return false;
        }

        if (strtolower($frameHost) !== strtolower($request->getUri()->getHost())) {
            return false;
        }

        $framePort = $frame->port();

        return $framePort === null || $framePort === $request->getUri()->getPort();
    }

    private function write(
        RawExchangeFrame $frame,
        string $method,
        string $endpoint,
        string $requestBody,
        bool $streaming,
        int $status,
        string $rawResponse,
        float $startedAt,
    ): void {
        try {
            AiProviderExchange::create([
                'provider' => $frame->provider,
                'endpoint' => $endpoint,
                'method' => $method,
                'streaming' => $streaming,
                'http_status' => $status,
                'request_body' => $requestBody !== '' ? $requestBody : null,
                'raw_response' => $rawResponse !== '' ? $rawResponse : null,
                'model' => $frame->model,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'ai_system_id' => $frame->aiSystemId,
                'ai_conversation_id' => $frame->aiConversationId,
                'ai_llm_message_id' => $frame->aiLlmMessageId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record provider exchange', ['exception' => $e->getMessage()]);
        }
    }
}

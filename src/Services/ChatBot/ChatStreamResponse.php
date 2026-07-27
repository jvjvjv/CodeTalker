<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot;

use Closure;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Wraps a turn's SSE chunks in the streamed response the browser consumes.
 *
 * Two details are load-bearing. The connection is kept alive past a browser
 * abort so the conversation service can notice the disconnect, stop generating,
 * and still persist the partial turn instead of being killed mid-flush. And
 * every chunk is flushed immediately, since PHP only reports a dead connection
 * once output has actually been pushed to it.
 */
class ChatStreamResponse
{
    /**
     * @param Closure(): iterable<string> $chunks  deferred so the turn only
     *        starts once the response is actually being streamed
     */
    public function make(string $chatHash, Closure $chunks): StreamedResponse
    {
        return response()->stream(function () use ($chunks): void {
            ignore_user_abort(true);

            $this->send(json_encode([
                'type' => 'status',
                'phase' => 'request_received',
                'message' => 'Preparing your request.',
            ]));

            try {
                foreach ($chunks() as $chunk) {
                    echo $chunk;
                    $this->flush();
                }
            } catch (\Throwable $e) {
                Log::error('Chat bot stream failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

                $this->send(json_encode(['type' => 'error', 'message' => 'Stream failed unexpectedly.']), flush: false);
                echo "data: [DONE]\n\n";
                $this->flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'X-Chat-Hash' => $chatHash,
        ]);
    }

    private function send(string $json, bool $flush = true): void
    {
        echo 'data: ' . $json . "\n\n";

        if ($flush) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}

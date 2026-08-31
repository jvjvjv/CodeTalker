<?php

namespace Jvjvjv\CodeTalker\Services\RawExchange;

use Closure;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Wraps a response body stream, buffering every byte read by the consumer and
 * flushing the buffer to a callback exactly once — on EOF or on close/destruct.
 *
 * Both triggers are required: laravel/ai's SSE parser returns at `[DONE]` and
 * may not read the inner stream to true EOF, so close() is the backstop.
 */
class TeeingStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private string $buffer = '';

    private bool $flushed = false;

    /** @param Closure(string): void $onFlush */
    public function __construct(
        private StreamInterface $stream,
        private Closure $onFlush,
    ) {
    }

    /**
     * Record bytes a consumer read from the underlying resource directly.
     *
     * The heartbeat SSE reader detaches the resource so it can fread() with a
     * stream timeout — those reads never pass through read() above, so the
     * reader feeds the tee itself. Flushing still happens on close/destruct.
     */
    public function record(string $bytes): void
    {
        if ($bytes !== '') {
            $this->buffer .= $bytes;
        }
    }

    public function read($length): string
    {
        $data = $this->stream->read($length);

        if ($data !== '') {
            $this->buffer .= $data;
        }

        if ($this->stream->eof()) {
            $this->flush();
        }

        return $data;
    }

    public function close(): void
    {
        $this->flush();
        $this->stream->close();
    }

    public function __destruct()
    {
        $this->flush();
    }

    private function flush(): void
    {
        if ($this->flushed) {
            return;
        }

        $this->flushed = true;

        ($this->onFlush)($this->buffer);
    }
}

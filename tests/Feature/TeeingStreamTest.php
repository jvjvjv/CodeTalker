<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use GuzzleHttp\Psr7\Utils;
use Jvjvjv\CodeTalker\Services\RawExchange\TeeingStream;
use Jvjvjv\CodeTalker\Tests\TestCase;

class TeeingStreamTest extends TestCase
{
    public function test_it_passes_bytes_through_and_flushes_once_on_eof(): void
    {
        $flushed = [];
        $tee = new TeeingStream(
            Utils::streamFor('hello world'),
            function (string $bytes) use (&$flushed): void {
                $flushed[] = $bytes;
            },
        );

        $read = '';
        while (! $tee->eof()) {
            $read .= $tee->read(4);
        }

        $this->assertSame('hello world', $read);
        $this->assertSame(['hello world'], $flushed);
    }

    public function test_it_flushes_on_close_without_reaching_eof(): void
    {
        $flushed = [];
        $tee = new TeeingStream(
            Utils::streamFor('data: {}\n\ndata: [DONE]'),
            function (string $bytes) use (&$flushed): void {
                $flushed[] = $bytes;
            },
        );

        // Read only part of the stream, then close (mirrors the SSE parser
        // returning at [DONE] before the inner stream reports EOF).
        $tee->read(6);
        $tee->close();

        $this->assertCount(1, $flushed);
        $this->assertSame('data: ', $flushed[0]);
    }
}

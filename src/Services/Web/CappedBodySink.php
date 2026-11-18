<?php

namespace Jvjvjv\CodeTalker\Services\Web;

/**
 * A response sink that accepts at most a fixed number of bytes.
 *
 * {@see WebFetcher} hands one of these to Guzzle as the `sink` option. The
 * handler writes the body here as it arrives — already decoded when cURL
 * decompresses it — and once the cap is reached a write reports fewer bytes
 * than it was given. cURL treats that short write as a reason to abort the
 * transfer, so neither the wire bytes nor their decompressed form are held
 * beyond the cap.
 *
 * It is a stream wrapper rather than a PSR-7 stream because Laravel's
 * `Http::fake()` writes a stub body into the sink with `fwrite()`, which needs
 * a real resource.
 */
final class CappedBodySink
{
    private const PROTOCOL = 'code-talker-capped-body';

    /** @var resource|null Set by PHP when the stream is opened. */
    public $context;

    /** @var resource|null */
    private $buffer;

    private int $cap = 0;

    private int $written = 0;

    private bool $reachedCap = false;

    /**
     * Open a sink that keeps at most $cap bytes.
     *
     * @return resource
     */
    public static function open(int $cap)
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $resource = fopen(
            self::PROTOCOL . '://body',
            'w+',
            false,
            stream_context_create([self::PROTOCOL => ['cap' => max(0, $cap)]]),
        );

        if ($resource === false) {
            throw new \RuntimeException('Could not open the response body sink.');
        }

        return $resource;
    }

    /**
     * Whether a write to this sink was cut short by the cap.
     *
     * @param resource $resource
     */
    public static function reachedCap($resource): bool
    {
        $wrapper = stream_get_meta_data($resource)['wrapper_data'] ?? null;

        return $wrapper instanceof self && $wrapper->reachedCap;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $settings = $this->context !== null ? stream_context_get_options($this->context) : [];
        $this->cap = (int) ($settings[self::PROTOCOL]['cap'] ?? 0);

        $buffer = fopen('php://temp', 'w+');

        if ($buffer === false) {
            return false;
        }

        $this->buffer = $buffer;

        return true;
    }

    public function stream_write(string $data): int
    {
        $room = $this->cap - $this->written;
        $accepted = strlen($data) <= $room ? $data : substr($data, 0, max(0, $room));

        if ($accepted !== $data) {
            $this->reachedCap = true;
        }

        if ($accepted === '') {
            return 0;
        }

        $count = (int) fwrite($this->buffer, $accepted);
        $this->written += $count;

        return $count;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->buffer, $count);
    }

    public function stream_eof(): bool
    {
        return feof($this->buffer);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return fseek($this->buffer, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        return (int) ftell($this->buffer);
    }

    /**
     * @return array<string, int>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->buffer);
    }

    public function stream_flush(): bool
    {
        return fflush($this->buffer);
    }

    public function stream_truncate(int $size): bool
    {
        $this->written = min($this->written, $size);

        return ftruncate($this->buffer, $size);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        if (is_resource($this->buffer)) {
            fclose($this->buffer);
        }
    }
}

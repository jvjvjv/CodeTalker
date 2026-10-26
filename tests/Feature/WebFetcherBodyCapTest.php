<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Services\Web\HostGate;
use Jvjvjv\CodeTalker\Services\Web\RequestPolicy;
use Jvjvjv\CodeTalker\Services\Web\WebFetcher;
use Jvjvjv\CodeTalker\Tests\TestCase;

/**
 * The body cap against a real transfer.
 *
 * `Http::fake()` hands back a complete body and never runs the handler's
 * sink, so it cannot show that a download stops at the cap. These tests run
 * PHP's built-in server on a loopback port instead; no DNS is involved.
 */
class WebFetcherBodyCapTest extends TestCase
{
    private const CAP = 1_000_000;

    /** @var resource|null */
    private $server = null;

    private string $root = '';

    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/code-talker-body-cap-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        file_put_contents($this->root . '/index.php', <<<'PHP'
            <?php
            $bytes = (int) ($_GET['bytes'] ?? 0);
            header('Content-Type: text/plain');

            if (isset($_GET['gzip'])) {
                header('Content-Encoding: gzip');
                echo gzencode(str_repeat('a', $bytes));

                return;
            }

            $chunk = str_repeat('a', 65536);

            for ($sent = 0; $sent < $bytes; $sent += 65536) {
                echo $chunk;
                flush();
            }
            PHP);

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
        fclose($probe);

        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $this->root],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        ) ?: null;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        $this->markTestSkipped('The built-in PHP server did not start.');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        @unlink($this->root . '/index.php');
        @rmdir($this->root);

        parent::tearDown();
    }

    private function fetcher(): WebFetcher
    {
        return new WebFetcher(new HostGate('refused: %s'), null, 'http-request');
    }

    private function policy(): RequestPolicy
    {
        return RequestPolicy::declared(['allowed_methods' => ['GET'], 'allow_private_hosts' => true]);
    }

    /**
     * One small request first, so the measurement excludes the classes a
     * first request loads.
     */
    private function warmUp(WebFetcher $fetcher): void
    {
        $fetcher->request('GET', sprintf('http://127.0.0.1:%d/?bytes=1', $this->port), $this->policy());
    }

    public function testADownloadStopsAtTheBodyCap(): void
    {
        config(['code-talker.tools.web_fetcher.max_body_length' => self::CAP]);

        $fetcher = $this->fetcher();
        $this->warmUp($fetcher);

        memory_reset_peak_usage();
        $baseline = memory_get_usage();

        $result = $fetcher->request(
            'GET',
            sprintf('http://127.0.0.1:%d/?bytes=%d', $this->port, self::CAP * 20),
            $this->policy(),
            truncate: false,
        );

        $peak = memory_get_peak_usage() - $baseline;

        $this->assertFalse($result->failed(), (string) $result->error);
        $this->assertSame(200, $result->status);
        $this->assertSame(self::CAP, strlen($result->content));
        $this->assertLessThan(self::CAP * 4, $peak);
    }

    public function testACompressedDownloadIsCappedOnItsDecodedSize(): void
    {
        config(['code-talker.tools.web_fetcher.max_body_length' => self::CAP]);

        $fetcher = $this->fetcher();
        $this->warmUp($fetcher);

        memory_reset_peak_usage();
        $baseline = memory_get_usage();

        $result = $fetcher->request(
            'GET',
            sprintf('http://127.0.0.1:%d/?gzip=1&bytes=%d', $this->port, self::CAP * 20),
            $this->policy(),
            truncate: false,
        );

        $peak = memory_get_peak_usage() - $baseline;

        $this->assertFalse($result->failed(), (string) $result->error);
        $this->assertSame(self::CAP, strlen($result->content));
        $this->assertLessThan(self::CAP * 4, $peak);
    }

    public function testABodyUnderTheCapArrivesWhole(): void
    {
        config(['code-talker.tools.web_fetcher.max_body_length' => self::CAP]);

        $result = $this->fetcher()->fetchPage(
            sprintf('http://127.0.0.1:%d/?bytes=%d', $this->port, 65536),
            $this->policy(),
            truncate: false,
        );

        $this->assertFalse($result->failed(), (string) $result->error);
        $this->assertSame(65536, strlen($result->content));
    }
}

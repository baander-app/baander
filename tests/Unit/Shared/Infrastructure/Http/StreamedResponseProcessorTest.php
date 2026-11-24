<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * StreamedResponseProcessor on a real process-mode Swoole server (streamed-response-probe.php):
 * a response that ends sends the chunked terminator, and one that is cut, because its content
 * failed or the server stops, closes its connection without it, which curl reports as a partial
 * transfer (exit 18).
 */
final class StreamedResponseProcessorTest extends TestCase
{
    private const int CURL_PARTIAL_FILE = 18;
    private const int RANGE_BYTES = 64 * 1_048_576;

    private string $directory;
    private Process $server;
    private int $port;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-streamed-response-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $range = fopen($this->directory . '/range.bin', 'wb');
        self::assertNotFalse($range);
        self::assertTrue(ftruncate($range, self::RANGE_BYTES));
        fclose($range);

        $this->server = new Process([PHP_BINARY, __DIR__ . '/streamed-response-probe.php', $this->directory]);
        // As in production. With Xdebug's debug mode a coroutine woken by Coroutine::cancel() can
        // lose its exception handling or crash the worker (Swoole 6.2).
        $this->server->setEnv(['XDEBUG_MODE' => 'off']);
        $this->server->setTimeout(60);
        $this->server->start();
        $deadline = microtime(true) + 10;
        while (!is_file($this->directory . '/port') && microtime(true) < $deadline && $this->server->isRunning()) {
            usleep(20_000);
        }
        $this->port = (int) @file_get_contents($this->directory . '/port');
        self::assertGreaterThan(0, $this->port, 'The probe server did not start: ' . $this->server->getErrorOutput());
        $this->awaitStats(static fn (array $stats): bool => true);
    }

    protected function tearDown(): void
    {
        $this->server->stop(2, SIGTERM);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testAStreamThatEndsSendsItsWholeBodyAndTheTerminator(): void
    {
        [$exit, $body] = $this->curl('/ends');

        self::assertSame(0, $exit);
        self::assertSame('first-last', $body);
    }

    public function testAGeneratorThatThrowsMidStreamClosesTheConnectionWithoutTheTerminator(): void
    {
        [$exit, $body] = $this->curl('/throws');

        self::assertSame(self::CURL_PARTIAL_FILE, $exit, 'curl did not see a partial transfer');
        self::assertSame('first-', $body);
        self::assertStringContainsString(
            '[StreamedResponse] RuntimeException: the encode stopped',
            (string) @file_get_contents($this->directory . '/errors'),
        );
        $this->awaitStats(static fn (array $stats): bool => $stats === ['requests' => 0, 'streams' => 0]);
    }

    public function testAStreamCutByTheDrainEndsAsATransferErrorAndItsCoroutineStops(): void
    {
        $client = $this->startCurl('/held');
        $this->awaitStats(static fn (array $stats): bool => $stats['streams'] === 1);

        self::assertSame('1', $this->get('/abort'));
        self::assertSame(0, $client->wait());
        [$exit, $body] = $this->curlResult();

        self::assertSame(self::CURL_PARTIAL_FILE, $exit);
        self::assertSame(str_pad('first-', 16_384, '.'), $body);
        // The coroutine that waited for more to send was woken, and the request was released.
        $this->awaitStats(static fn (array $stats): bool => $stats === ['requests' => 0, 'streams' => 0]);
        self::assertSame("held_cancelled\n", file_get_contents($this->directory . '/events'));
        self::assertFileDoesNotExist($this->directory . '/errors', 'A cut stream is not an error to report.');
    }

    public function testCancellingABlockedWriteStopsBufferedProductionBeforeTheNextChunk(): void
    {
        $socket = stream_socket_client('tcp://127.0.0.1:'.$this->port, $errno, $error, 5);
        self::assertIsResource($socket, $error);
        stream_set_timeout($socket, 5);
        try {
            fwrite($socket, "GET /buffered HTTP/1.1\r\nHost: baander.app\r\nConnection: close\r\n\r\n");
            $headers = '';
            while (!str_ends_with($headers, "\r\n\r\n")) {
                $byte = fread($socket, 1);
                if ($byte === false || $byte === '') {
                    throw new \RuntimeException('The stream headers ended early.');
                }
                $headers .= $byte;
            }
            self::assertStringContainsString('200', $headers);
            $deadline = microtime(true) + 5;
            do {
                $state = json_decode($this->get('/buffered-state'), true, flags: JSON_THROW_ON_ERROR);
                if ($state['blocked']) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            self::assertTrue($state['blocked'], 'The sender never blocked in Response::write.');
            $result = json_decode($this->get('/abort-buffered'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(1, $result['cut']);
            self::assertSame($result['before'], $result['after'], 'Cancellation produced another buffered chunk.');
            self::assertTrue($result['finished']);
            $wire = stream_get_contents($socket);
            self::assertIsString($wire);
            self::assertFalse(stream_get_meta_data($socket)['timed_out']);
            self::assertFalse(str_ends_with($wire, "\r\n0\r\n\r\n"), 'A cancelled stream sent the chunked terminator.');
            $this->awaitStats(static fn (array $stats): bool => $stats === ['requests' => 0, 'streams' => 0]);
            self::assertSame("buffered_finally\n", file_get_contents($this->directory.'/events'));
            self::assertStringNotContainsString('buffered-body-', $this->server->getOutput());
            self::assertFileDoesNotExist($this->directory.'/errors', 'Cancellation leaked an error or body into the log.');
        } finally {
            fclose($socket);
        }
    }

    public function testARangedFileResponseCutMidLoopIsShorterThanItsContentLength(): void
    {
        $client = $this->startCurl('/range', ['--limit-rate', '8M']);
        $this->awaitStats(static fn (array $stats): bool => $stats['streams'] === 1);

        self::assertSame('1', $this->get('/abort'));
        self::assertSame(0, $client->wait());
        [$exit, $body] = $this->curlResult();

        self::assertSame(self::CURL_PARTIAL_FILE, $exit);
        self::assertGreaterThan(0, strlen($body));
        self::assertLessThan(self::RANGE_BYTES, strlen($body));
        $this->awaitStats(static fn (array $stats): bool => $stats === ['requests' => 0, 'streams' => 0]);
    }

    /**
     * @param list<string> $options
     * @return array{int, string} curl's exit code and the body
     */
    private function curl(string $path, array $options = []): array
    {
        self::assertSame(0, $this->startCurl($path, $options)->wait());

        return $this->curlResult();
    }

    /** @param list<string> $options */
    private function startCurl(string $path, array $options = []): Process
    {
        // The exit code goes to a file, so the Process itself always succeeds.
        $process = new Process([
            'sh', '-c', 'dir=$1 url=$2; shift 2; curl -sS --max-time 20 "$@" -o "$dir/body" "$url"; echo $? > "$dir/exit"',
            'sh', $this->directory, 'http://127.0.0.1:' . $this->port . $path, ...$options,
        ]);
        @unlink($this->directory . '/exit');
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /** @return array{int, string} */
    private function curlResult(): array
    {
        return [(int) file_get_contents($this->directory . '/exit'), (string) @file_get_contents($this->directory . '/body')];
    }

    private function get(string $path): string
    {
        $body = $this->fetch($path);
        self::assertNotFalse($body, 'The probe server did not answer ' . $path);

        return $body;
    }

    /** The body of a successful GET, or false. It runs in this process, beside a transfer the curl command still holds open. */
    private function fetch(string $path): string|false
    {
        $curl = curl_init('http://127.0.0.1:' . $this->port . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FAILONERROR => true, CURLOPT_TIMEOUT => 5]);

        return curl_exec($curl);
    }

    /** @param callable(array{requests: int, streams: int}): bool $until */
    private function awaitStats(callable $until): void
    {
        $deadline = microtime(true) + 5;
        do {
            $stats = json_decode((string) $this->fetch('/stats'), true);
            if (is_array($stats) && $until($stats)) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        self::fail('The probe server never reached the expected state; last: ' . json_encode($stats ?? null));
    }
}

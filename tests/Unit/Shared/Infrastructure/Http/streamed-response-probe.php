<?php

declare(strict_types=1);

/*
 * A process-mode Swoole HTTP server that sends its responses through StreamedResponseProcessor,
 * for StreamedResponseProcessorTest. Usage: streamed-response-probe.php <directory>; it writes its
 * port to <directory>/port and its event lines to <directory>/events, and PHP's error log goes to
 * <directory>/errors.
 *
 *   /ends     a stream that ends normally
 *   /throws   a stream whose generator throws after its first chunk
 *   /held     a stream that waits forever after its first 16 KB chunk, as a rendition still encoding does
 *   /range    a byte-range file response of <directory>/range.bin
 *   /abort    cuts every stream in flight (InFlightStreamAborter, as WorkerDrain does) and answers
 *             how many were in flight
 *   /stats    the requests and streams in flight, not counting this one
 */

require dirname(__DIR__, 5) . '/vendor/autoload.php';

use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Swoole\CoroutineCanceledException;
use App\Shared\Infrastructure\Swoole\Drain\InFlightStreamAborter;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as BundleSwoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\InFlightStreams;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\ResponseHeadersAndStatusProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\StreamedResponseProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\SwooleRangeFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

$directory = (string) ($_SERVER['argv'][1] ?? '');
ini_set('error_log', $directory . '/errors');
$rangeFile = $directory . '/range.bin';
$record = static function (string $event) use ($directory): void {
    file_put_contents($directory . '/events', $event . "\n", FILE_APPEND | LOCK_EX);
};

$server = new Server('127.0.0.1', 0, SWOOLE_PROCESS);
$server->set([
    'worker_num' => 1,
    'reactor_num' => 1,
    'enable_coroutine' => true,
    'hook_flags' => SWOOLE_HOOK_ALL,
    'http_compression' => false,
    'log_file' => $directory . '/swoole.log',
    'max_wait_time' => 1,
]);
$streams = new InFlightStreams(static fn (int $fd): bool => $server->exist($fd) && $server->close($fd, true));
$aborter = new InFlightStreamAborter($streams);
$processor = new ResponseHeadersAndStatusProcessor(new StreamedResponseProcessor(
    new CoWrapper(new ServicePoolContainer([]), new BundleSwoole()),
    $streams,
));

$server->on('request', static function (SwooleRequest $request, SwooleResponse $response) use ($server, $streams, $aborter, $processor, $rangeFile, $record): void {
    $foundation = match ($request->server['request_uri']) {
        '/ends' => new StreamedResponse(static function (): void {
            echo 'first-';
            flush();
            Async::sleep(0.05);
            echo 'last';
            flush();
        }),
        '/throws' => new StreamedResponse(static function (): void {
            echo 'first-';
            flush();
            Async::sleep(0.05);
            throw new RuntimeException('the encode stopped');
        }),
        '/held' => new StreamedResponse(static function () use ($record): void {
            // Larger than the processor's output buffer, as a rendition's chunks are, so it is sent at once.
            echo str_pad('first-', 16_384, '.');
            flush();
            try {
                while (true) {
                    Async::sleep(0.05);
                }
            } catch (CoroutineCanceledException $cancelled) {
                $record('held_cancelled');
                throw $cancelled;
            }
        }),
        '/range' => new SwooleRangeFileResponse($rangeFile, 0, (int) filesize($rangeFile), (int) filesize($rangeFile), 'application/octet-stream'),
        default => null,
    };
    if ($foundation !== null) {
        $processor->process($foundation, $response);

        return;
    }
    $response->end(match ($request->server['request_uri']) {
        '/abort' => (static function () use ($streams, $aborter): string {
            $inFlight = $streams->count();
            $aborter->abortAll();

            return (string) $inFlight;
        })(),
        '/stats' => json_encode([
            'requests' => (int) $server->stats()['worker_concurrency'] - 1,
            'streams' => $streams->count(),
        ], JSON_THROW_ON_ERROR),
        default => 'unknown',
    });
});
file_put_contents($directory . '/port', (string) $server->port);
$server->start();

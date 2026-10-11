<?php

declare(strict_types=1);

/*
 * Clients for the drain scenarios of scripts/test-web-shutdown-container.sh. Runs in the web
 * container, next to app:serve on 127.0.0.1:9501, and prints one timestamped line per outcome.
 *
 *   ws <user>                       a WebSocket that sends a ping frame every 200 ms and records
 *                                   each pong, any other frame and the close; it answers a close
 *                                   frame with its own, as a browser does
 *   stalled-stream <user> <track>   a progressive stream of an in-progress rendition that stops
 *                                   reading once the response has started, while this process
 *                                   keeps writing the rendition so the server's buffers fill up
 *   keepalive-ready <user>          one kept-alive connection that asks for /ready every 200 ms
 *                                   and records each status and Connection header, until an
 *                                   answer says Connection: close or the connection ends
 *   held-stream <user> <track>      a progressive stream of a rendition this process holds in
 *                                   progress and never completes, read as it arrives; records how
 *                                   the response ended (curl's result code and the bytes received)
 *   completing-stream <user> <track>
 *                                   a progressive stream of a rendition this process holds in
 *                                   progress; one second after /tmp/drain-stop-requested appears it
 *                                   completes the rendition, and records how the response ended
 *   ranged-file <user> <track>      a byte-range request for a complete 16 MB rendition, read at
 *                                   1 MB/s; records the Content-Length and how the response ended
 *   completing-file <user> <track>  a request for a complete 4 MB rendition, read at 2 MB/s, so it
 *                                   takes about 1.6 seconds; records the Content-Length and how
 *                                   the response ended
 *   fifo-encode <user> <track>      replaces the track's source with a FIFO that a real-time
 *                                   FFmpeg writes, so the server's encoder runs as long as this
 *                                   process lets it, and streams the encode; records the writer's
 *                                   process id. Run where app:serve is not PID 1
 */

use App\Tests\Fixtures\WebRuntime\HeldRendition;
use App\Tests\Fixtures\WebRuntime\StreamClient;
use Doctrine\ORM\EntityManagerInterface;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\CloseFrame;
use Swoole\WebSocket\Frame;

use function Swoole\Coroutine\run;

$projectDir = dirname(__DIR__, 3);
require $projectDir . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($projectDir . '/.env');

function record(string $event, string $detail = ''): void
{
    fwrite(STDOUT, sprintf("client %.6f %s %s\n", microtime(true), $event, $detail));
}

/**
 * Reads one HTTP/1.1 response with a Content-Length body.
 *
 * @param resource $socket
 * @return array{int, array<string, string>}|null the status and the lower-cased headers, or null at EOF
 */
function readResponse($socket): ?array
{
    $head = '';
    while (!str_ends_with($head, "\r\n\r\n")) {
        $line = fgets($socket);
        if ($line === false) {
            return null;
        }
        $head .= $line;
    }
    $lines = explode("\r\n", trim($head));
    if (preg_match('#^HTTP/1\.1 (\d{3}) #', $lines[0], $status) !== 1) {
        throw new RuntimeException('Unexpected status line ' . $lines[0]);
    }
    $headers = [];
    foreach (array_slice($lines, 1) as $header) {
        [$name, $value] = explode(':', $header, 2) + [1 => ''];
        $headers[strtolower(trim($name))] = trim($value);
    }
    $length = (int) ($headers['content-length'] ?? 0);
    $body = $length > 0 ? stream_get_contents($socket, $length) : '';
    if (strlen((string) $body) !== $length) {
        return null;
    }

    return [(int) $status[1], $headers];
}

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];
[, $mode, $user] = $arguments + [null, '', ''];
set_exception_handler(static function (Throwable $error) use ($mode): never {
    fwrite(STDERR, sprintf("drain-client %s failed: %s\n  at %s:%d\n", $mode, $error->getMessage(), $error->getFile(), $error->getLine()));
    exit(1);
});
$kernel = new App\Kernel('prod', false);
$kernel->boot();
$manager = $kernel->getContainer()->get('doctrine')->getManager();
if (!$manager instanceof EntityManagerInterface) {
    throw new RuntimeException('No entity manager.');
}
$client = new StreamClient(
    $manager,
    $user,
    (string) file_get_contents($projectDir . '/config/secrets/oauth/private.key'),
    'http://127.0.0.1:9501/api/stream/track',
);

if ($mode === 'ws') {
    $token = $client->accessToken();
    run(static function () use ($token): void {
        $ws = new Client('127.0.0.1', 9501);
        $ws->set(['timeout' => 5, 'open_websocket_close_frame' => true, 'open_websocket_pong_frame' => true]);
        if (!$ws->upgrade('/api/ws?token=' . rawurlencode($token))) {
            record('ws_upgrade_failed', sprintf('status=%d errno=%d', $ws->statusCode, $ws->errCode));

            return;
        }
        record('ws_open', 'status=' . $ws->statusCode);
        while (true) {
            $ws->push('', WEBSOCKET_OPCODE_PING);
            $frame = $ws->recv(0.2);
            if ($frame instanceof CloseFrame) {
                record('ws_close_frame', sprintf('code=%d reason=%s', $frame->code, $frame->reason));
                // A browser answers a close frame with its own; the server then closes the connection.
                $ws->push(pack('n', 1000), WEBSOCKET_OPCODE_CLOSE);
                continue;
            }
            if ($frame === false && $ws->errCode === SOCKET_ETIMEDOUT) {
                continue;
            }
            if ($frame === false || $frame === '') {
                record('ws_closed', 'errno=' . $ws->errCode);

                return;
            }
            if ($frame instanceof Frame && $frame->opcode === WEBSOCKET_OPCODE_PONG) {
                record('ws_pong');
            } elseif ($frame instanceof Frame) {
                record('ws_frame', 'opcode=' . $frame->opcode);
            }
            Swoole\Coroutine::sleep(0.2);
        }
    });
} elseif ($mode === 'stalled-stream') {
    $track = $arguments[3] ?? '';
    $sourceFile = $projectDir . '/storage/media/web-runtime/track.flac';
    $held = new HeldRendition((string) getenv('CONVERT_STORAGE_PATH'), $track, $sourceFile, 'mp3', 64);
    $held->append(HeldRendition::bytes(65_536, 'drain-first'));
    $stream = $client->open('id=' . $track . '&format=mp3&bitrate=64000');
    if (!$stream->until(static fn ($exchange): bool => strlen($exchange->body) > 0)) {
        throw new RuntimeException(sprintf('The stream did not start (status %d).', $stream->status()));
    }
    record('stream_open', 'status=' . $stream->status());
    // From here on this process never reads the response again. It writes far more than the
    // server and kernel buffer for one connection, so the server's writes back up.
    for ($megabyte = 0; $megabyte < 48; ++$megabyte) {
        $held->append(HeldRendition::bytes(1_048_576, 'drain-' . $megabyte));
    }
    record('stalled', 'written=' . strlen($held->written));
    sleep(3600);
} elseif ($mode === 'keepalive-ready') {
    $socket = stream_socket_client('tcp://127.0.0.1:9501', $errno, $error, 5);
    if ($socket === false) {
        throw new RuntimeException(sprintf('Cannot connect: %s (%d)', $error, $errno));
    }
    stream_set_timeout($socket, 10);
    while (true) {
        $request = "GET /ready HTTP/1.1\r\nHost: 127.0.0.1:9501\r\nConnection: keep-alive\r\n\r\n";
        if (@fwrite($socket, $request) !== strlen($request)) {
            record('ready_eof', 'when=send');
            break;
        }
        $response = readResponse($socket);
        if ($response === null) {
            record('ready_eof', 'when=read');
            break;
        }
        [$status, $headers] = $response;
        record('ready', sprintf('status=%d connection=%s', $status, strtolower($headers['connection'] ?? '-')));
        if (strtolower($headers['connection'] ?? '') === 'close') {
            break;
        }
        usleep(200_000);
    }
} elseif (in_array($mode, ['held-stream', 'completing-stream', 'ranged-file', 'completing-file'], true)) {
    $track = $arguments[3] ?? '';
    $sourceFile = $projectDir . '/storage/media/web-runtime/track.flac';
    // Each mode holds a rendition of its own; the server names renditions by format and bitrate.
    [$format, $kbps] = [
        'held-stream' => ['aac', 96],
        'completing-stream' => ['aac', 128],
        'ranged-file' => ['opus', 64],
        'completing-file' => ['opus', 96],
    ][$mode];
    $held = new HeldRendition((string) getenv('CONVERT_STORAGE_PATH'), $track, $sourceFile, $format, $kbps);
    $query = sprintf('id=%s&format=%s&bitrate=%d', $track, $format, $kbps * 1000);
    if ($mode === 'ranged-file') {
        $held->append(HeldRendition::bytes(16 * 1_048_576, 'ranged'));
        $held->complete();
        $exchange = $client->open($query, ['Range: bytes=0-'], maxBytesPerSecond: 1_048_576);
    } elseif ($mode === 'completing-file') {
        $held->append(HeldRendition::bytes(4 * 1_048_576, 'completing-file'));
        $held->complete();
        $exchange = $client->open($query, maxBytesPerSecond: 2 * 1_048_576);
    } else {
        $held->append(HeldRendition::bytes(65_536, $mode . '-first'));
        $exchange = $client->open($query);
    }
    if (!$exchange->until(static fn ($exchange): bool => strlen($exchange->body) > 0)) {
        throw new RuntimeException(sprintf('The response did not start (status %d).', $exchange->status()));
    }
    record('streaming', sprintf('status=%d length=%s', $exchange->status(), $exchange->header('Content-Length') ?? '-'));
    $completeAt = null;
    try {
        $exchange->until(static function () use ($mode, $held, &$completeAt): bool {
            if ($mode === 'completing-stream' && $completeAt === null && is_file('/tmp/drain-stop-requested')) {
                $completeAt = microtime(true) + 1.0;
            }
            if ($completeAt !== null && microtime(true) >= $completeAt) {
                $completeAt = INF;
                $held->append(HeldRendition::bytes(65_536, 'completing-rest'));
                $held->complete();
                record('completed', 'written=' . strlen($held->written));
            }

            return false;
        }, 3600.0);
    } catch (RuntimeException) {
        // A cut response: curl's result code says how.
    }
    record('ended', sprintf(
        'result=%d bytes=%d complete=%s',
        $exchange->result,
        strlen($exchange->body),
        $exchange->body === $held->written ? 'yes' : 'no',
    ));
} elseif ($mode === 'fifo-encode') {
    $track = $arguments[3] ?? '';
    $sourceFile = $projectDir . '/storage/media/web-runtime/track.flac';
    if (!is_file($sourceFile . '.real') && !rename($sourceFile, $sourceFile . '.real')) {
        throw new RuntimeException('Cannot move the track source aside.');
    }
    if (!file_exists($sourceFile) && !posix_mkfifo($sourceFile, 0644)) {
        throw new RuntimeException('Cannot create the FIFO.');
    }
    // FFmpeg opens the FIFO for writing once the server's encoder opens it for reading, then
    // writes ten minutes of audio in real time.
    $writer = proc_open([
        'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-re',
        '-f', 'lavfi', '-i', 'sine=frequency=440:duration=600', '-ac', '2', '-ar', '44100',
        '-c:a', 'flac', '-f', 'flac', '-y', $sourceFile,
    ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/tmp/drain-fifo-writer.log', 'a']], $pipes);
    if ($writer === false) {
        throw new RuntimeException('Cannot start the FIFO writer.');
    }
    $exchange = $client->open('id=' . $track . '&format=mp3&bitrate=128000');
    if (!$exchange->until(static fn ($exchange): bool => strlen($exchange->body) > 0, 60.0)) {
        throw new RuntimeException(sprintf('The encode did not stream (status %d).', $exchange->status()));
    }
    record('encoding', 'writer=' . proc_get_status($writer)['pid']);
    try {
        $exchange->until(static fn (): bool => false, 3600.0);
    } catch (RuntimeException) {
    }
    record('ended', sprintf('result=%d bytes=%d', $exchange->result, strlen($exchange->body)));
    sleep(3600);
} else {
    throw new RuntimeException('Unknown mode ' . $mode);
}

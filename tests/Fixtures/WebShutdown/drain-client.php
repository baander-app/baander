<?php

declare(strict_types=1);

/*
 * Clients for the drain scenarios of scripts/test-web-shutdown-container.sh. Runs in the web
 * container, next to app:serve on 127.0.0.1:9501, and prints one timestamped line per outcome.
 *
 *   ws <user>                       a WebSocket that sends a ping frame every 200 ms and records
 *                                   each pong, any other frame and the close
 *   stalled-stream <user> <track>   a progressive stream of an in-progress rendition that stops
 *                                   reading once the response has started, while this process
 *                                   keeps writing the rendition so the server's buffers fill up
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
} else {
    throw new RuntimeException('Unknown mode ' . $mode);
}

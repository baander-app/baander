<?php

declare(strict_types=1);

// Client half of the shutdown-order probe (see shutdown-order-probe.php). It opens four
// connections before the stop and prints one timestamped line per outcome:
// - a kept-alive connection, used again once the drain has started;
// - a request the server holds until the drain starts;
// - a WebSocket, which should receive a close frame during the drain;
// - an idle connection that never sends a request, to see when the server closes it.
// A fifth, new connection is tried during the drain and its outcome recorded.

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Socket;
use Swoole\WebSocket\CloseFrame;

use function Swoole\Coroutine\run;

function record(string $event, string $detail = ''): void
{
    fwrite(STDOUT, sprintf("client %.6f %s %s\n", microtime(true), $event, $detail));
}

$host = $argv[1] ?? '127.0.0.1';
$port = 9501;

run(static function () use ($host, $port): void {
    $keep = new Client($host, $port);
    $keep->set(['timeout' => 10, 'keep_alive' => true]);
    $keep->get('/ping');
    record('keepalive_before', sprintf('status=%d body=%s', $keep->statusCode, $keep->body));

    $idle = new Socket(AF_INET, SOCK_STREAM, 0);
    if (!$idle->connect($host, $port, 5)) {
        record('idle_connect_failed', 'errno=' . $idle->errCode);

        return;
    }

    $ws = new Client($host, $port);
    // Return the server's close frame instead of answering it silently.
    $ws->set(['timeout' => 60, 'open_websocket_close_frame' => true]);
    if (!$ws->upgrade('/ws')) {
        record('ws_upgrade_failed', 'errno=' . $ws->errCode);

        return;
    }

    $drainStarted = new Channel(1);
    $done = new Channel(4);

    Coroutine::create(static function () use ($host, $port, $drainStarted, $done): void {
        $hold = new Client($host, $port);
        $hold->set(['timeout' => 60]);
        $hold->get('/hold');
        record('hold_response', sprintf('status=%d body=%s errno=%d', $hold->statusCode, (string) $hold->body, $hold->errCode));
        $drainStarted->push(true);
        $done->push('hold');
    });

    Coroutine::create(static function () use ($ws, $done): void {
        while (true) {
            $frame = $ws->recv(60);
            if ($frame instanceof CloseFrame) {
                record('ws_close_frame', sprintf('code=%d reason=%s', $frame->code, $frame->reason));
                continue;
            }
            if ($frame === false || $frame === '') {
                record('ws_eof', 'errno=' . $ws->errCode);
                break;
            }
        }
        $done->push('ws');
    });

    Coroutine::create(static function () use ($idle, $done): void {
        $data = $idle->recv(1, 60);
        record('idle_eof', sprintf('bytes=%d errno=%d', is_string($data) ? strlen($data) : -1, $idle->errCode));
        $done->push('idle');
    });

    record('ready');

    // The held response arrives once the drain has started.
    $drainStarted->pop(60);
    $keep->get('/ping');
    record('keepalive_during_drain', sprintf('status=%d body=%s errno=%d', $keep->statusCode, (string) $keep->body, $keep->errCode));

    $fresh = new Client($host, $port);
    $fresh->set(['timeout' => 1.5]);
    $fresh->get('/ping');
    record('new_connection_during_drain', sprintf('status=%d errno=%d', $fresh->statusCode, $fresh->errCode));

    for ($i = 0; $i < 3; $i++) {
        $done->pop(60);
    }
    record('client_done');
});

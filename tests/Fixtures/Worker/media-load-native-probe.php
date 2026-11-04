<?php

declare(strict_types=1);

// Measures, from the control unit as media children connect, how long the native daemon takes
// to answer a new peer. Every tenth of a second it connects to the daemon's socket and sends a
// request without a valid correlation, which the daemon answers with a refusal and no other
// effect. The time from connecting to the answer bounds the peer's wait in the listen backlog
// before the daemon accepted it. Writes one JSON line per probe.
// Arguments: seconds to run.

$seconds = (float) ($argv[1] ?? 600);
$socket = '/run/baander-worker/' . getenv('BAANDER_WORKER_BOOT_ID') . '/native.sock';
$deadline = hrtime(true) + (int) ($seconds * 1e9);
while (hrtime(true) < $deadline) {
    $started = hrtime(true);
    $record = ['t' => $started / 1e9];
    $peer = @stream_socket_client('unix://' . $socket, $errno, $error, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
    if ($peer === false) {
        $record['error'] = 'connect: ' . $error;
    } else {
        stream_set_blocking($peer, false);
        $request = "{\"id\":\"load-probe\"}\n";
        $written = 0;
        $reply = '';
        $limit = $started + 5_000_000_000;
        while (hrtime(true) < $limit && !str_contains($reply, "\n")) {
            if ($written < strlen($request)) {
                $written += (int) @fwrite($peer, substr($request, $written));
            }
            $chunk = @fread($peer, 65_536);
            if ($chunk === false || ($chunk === '' && feof($peer))) {
                break;
            }
            $reply .= $chunk;
            usleep(500);
        }
        fclose($peer);
        $decoded = json_decode(strtok($reply, "\n") ?: 'null', true);
        $record['seconds'] = round((hrtime(true) - $started) / 1e9, 4);
        if (!is_array($decoded) || ($decoded['ok'] ?? null) !== false || ($decoded['uncertain'] ?? null) !== false) {
            $record['error'] = 'unexpected reply';
        }
    }
    echo json_encode($record, JSON_THROW_ON_ERROR), "\n";
    flush();
    if (($stopFile = getenv('BAANDER_TEST_SAMPLER_STOP_FILE')) !== false && is_file($stopFile)) {
        break;
    }
    usleep(100_000);
}

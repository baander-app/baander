<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

global $argv;
$name = basename($argv[0]);
$mode = getenv('BACKUP_FIXTURE_MODE');
$root = getenv('BACKUP_FIXTURE_ROOT');
file_put_contents($root . '/calls', json_encode([$name, getmypid(), array_slice($argv, 1), microtime(true)], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
if ($name === 'curl') {
    $header = array_search('--dump-header', $argv, true);
    if ($header === false) {
        exit(2);
    }
    $headers = "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nTrailer: X-STREAM-ERROR\r\n\r\n";
    $headers = match ($mode) {
        'auth' => str_replace('200 OK', '401 Unauthorized', $headers),
        'redirect' => str_replace('200 OK', '301 Redirect', $headers),
        'content' => str_replace('application/octet-stream', 'text/plain', $headers),
        'stream' => $headers . "X-STREAM-ERROR: sqlite failed\r\n",
        'headers' => $headers . 'X-Long: ' . str_repeat('x', 70000) . "\r\n",
        default => $headers,
    };
    $headerPath = $argv[$header + 1];
    // php://fd duplicates inherited pipes; PHP's filesystem wrapper cannot
    // reopen a pipe through the Linux /dev/fd symlink.
    $stream = fopen(str_replace('/dev/fd/', 'php://fd/', $headerPath), 'wb');
    if ($stream === false) {
        exit(2);
    }
    $remaining = $headers;
    while ($remaining !== '') {
        $written = fwrite($stream, $remaining);
        if ($written === false || $written === 0) {
            exit(2);
        }
        $remaining = substr($remaining, $written);
    }
    fclose($stream);
    if ($mode === 'hang') {
        sleep(30);
    }
    if ($mode === 'cadence' && !file_exists($root . '/first-capture')) {
        file_put_contents($root . '/first-capture', 'started');
        sleep(1000);
    }
    echo ($mode === 'prefix' ? 'not a database!!' : "SQLite format 3\0") . str_repeat('x', 100);
    exit(in_array($mode, ['tls', 'incomplete'], true) ? 18 : 0);
}
if ($name === 'age') {
    $data = stream_get_contents(STDIN);
    if ($mode === 'encrypt') {
        exit(1);
    }
    echo "controlled-encryption-fixture\n" . $data;
    exit(0);
}
if ($name === 'rclone') {
    if ($mode === 'upload') {
        exit(1);
    }
    if ($mode === 'upload-hang') {
        sleep(30);
    }
    $index = array_search('copyto', $argv, true);
    if ($index === false) {
        exit(2);
    }
    exit(copy($argv[$index + 1], $root . '/objects/' . basename($argv[$index + 2])) ? 0 : 1);
}
exit(2);

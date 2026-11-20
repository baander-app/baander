#!/usr/bin/env php
<?php

declare(strict_types=1);

[$script, $root] = $argv ?? [];
$context = stream_context_create(['ssl' => ['local_cert' => $root . '/certificate.pem', 'local_pk' => $root . '/key.pem', 'verify_peer' => false]]);
$server = stream_socket_server('tls://127.0.0.1:0', $error, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    throw new RuntimeException($message);
}
file_put_contents($root . '/port', substr(strrchr((string) stream_socket_get_name($server, false), ':'), 1));
for ($attempt = 0; $attempt < 4; $attempt++) {
    $connection = stream_socket_accept($server, 15);
    if ($connection === false) {
        exit(1);
    }
    $request = '';
    while (!str_ends_with($request, "\r\n\r\n")) {
        $line = fgets($connection);
        if ($line === false) {
            exit(2);
        }
        $request .= $line;
    }
    if (preg_match('/^Content-Length: (\d+)/mi', $request, $length)) {
        $remaining = (int) $length[1];
        while ($remaining > 0) {
            $chunk = fread($connection, $remaining);
            if ($chunk === false || $chunk === '') {
                exit(3);
            }
            $request .= $chunk;
            $remaining -= strlen($chunk);
        }
    }
    file_put_contents($root . '/request-' . $attempt, $request);
    if ($attempt === 0) {
        $body = '{"error":"use_dpop_nonce"}';
        fwrite(
            $connection,
            "HTTP/1.1 401 Unauthorized\r\nDPoP-Nonce: qualified-nonce\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body,
        );
    } elseif ($attempt === 1) {
        $body = '{"authenticated":true}';
        fwrite(
            $connection,
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body,
        );
    } else {
        $body = "audio\0bytes";
        fwrite(
            $connection,
            "HTTP/1.1 200 OK\r\nContent-Type: audio/flac\r\nContent-Length: " . (strlen($body) + ($attempt === 3 ? 1 : 0)) . "\r\nConnection: close\r\n\r\n" . $body,
        );
    }
    fclose($connection);
}
fclose($server);

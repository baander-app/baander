<?php

declare(strict_types=1);

$directory = $argv[1] ?? throw new InvalidArgumentException('Fixture directory is required.');
$context = stream_context_create(['ssl' => [
    'local_cert' => $directory . '/certificate.pem',
    'local_pk' => $directory . '/key.pem',
]]);
$tls = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, context: $context);
$http = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($tls === false || $http === false) {
    exit(1);
}
$tlsPort = (int) substr(strrchr(stream_socket_get_name($tls, false), ':'), 1);
$httpPort = (int) substr(strrchr(stream_socket_get_name($http, false), ':'), 1);
fwrite(STDOUT, json_encode(['tls' => $tlsPort, 'http' => $httpPort], JSON_THROW_ON_ERROR) . "\n");
fflush(STDOUT);
$deadline = microtime(true) + 30;
while (microtime(true) < $deadline) {
    $read = [$tls, $http];
    $write = $except = [];
    if (stream_select($read, $write, $except, 5) === 0) {
        continue;
    }
    foreach ($read as $listener) {
        $connection = stream_socket_accept($listener, 1);
        if ($connection === false) {
            continue;
        }
        stream_set_timeout($connection, 2);
        $isTls = $listener === $tls;
        if ($isTls && @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
            fclose($connection);
            continue;
        }
        $line = fgets($connection);
        if ($line === false) {
            fclose($connection);
            continue;
        }
        $path = explode(' ', $line)[1];
        $authorization = '';
        while (($header = fgets($connection)) !== false && trim($header) !== '') {
            if (str_starts_with(strtolower($header), 'authorization:')) {
                $authorization = ' authorization=' . trim(substr($header, strlen('authorization:')));
            }
        }
        file_put_contents($directory . '/requests', ($isTls ? 'https ' : 'http ') . $path . $authorization . "\n", FILE_APPEND);
        $response = "HTTP/1.1 200 OK\r\n";
        if ($isTls && $path === '/stream-redirect') {
            $response = "HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:$httpPort/private\r\n";
        } elseif ($isTls && str_starts_with($path, '/api/search')) {
            $response = "HTTP/1.1 302 Found\r\nLocation: http://private.baander.app:$httpPort/private\r\n";
        }
        fwrite($connection, $response . "Content-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n[]");
        fclose($connection);
    }
}

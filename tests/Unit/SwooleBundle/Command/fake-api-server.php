<?php

declare(strict_types=1);

/*
 * A stand-in for the web server's API server, for ServerStopCommandTest. Listens on a free port of
 * 127.0.0.1 and prints "port <n>". GET /api/server answers a status whose master PID is the first
 * argument; DELETE /api/server answers 204, prints "stop requested" and ends this process. Kept-
 * alive connections are served. Gives up after 10 seconds without a stop request.
 */

$masterPid = (int) ($argv[1] ?? 0);
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($listener === false) {
    fwrite(STDERR, sprintf("Cannot listen: %s (%d)\n", $error, $errno));
    exit(1);
}
$name = (string) stream_socket_get_name($listener, false);
fwrite(STDOUT, 'port ' . substr($name, strrpos($name, ':') + 1) . "\n");

$giveUpAt = microtime(true) + 10;
while (microtime(true) < $giveUpAt) {
    $connection = @stream_socket_accept($listener, max(0.1, $giveUpAt - microtime(true)));
    if ($connection === false) {
        continue;
    }
    stream_set_timeout($connection, 5);
    while (true) {
        $head = '';
        while (!str_ends_with($head, "\r\n\r\n")) {
            $line = fgets($connection);
            if ($line === false) {
                fclose($connection);
                continue 3;
            }
            $head .= $line;
        }
        if (preg_match('/^content-length:\s*(\d+)/mi', $head, $length) === 1 && (int) $length[1] > 0) {
            fread($connection, (int) $length[1]);
        }
        [$method, $path] = explode(' ', $head, 3) + ['', '', ''];
        if ($method === 'GET' && $path === '/api/server') {
            $body = json_encode(['server' => ['processes' => ['master' => ['pid' => $masterPid]]]], JSON_THROW_ON_ERROR);
            fwrite($connection, sprintf("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: %d\r\n\r\n%s", strlen($body), $body));
        } elseif ($method === 'DELETE' && $path === '/api/server') {
            fwrite($connection, "HTTP/1.1 204 No Content\r\n\r\n");
            fclose($connection);
            fwrite(STDOUT, "stop requested\n");
            exit(0);
        } else {
            fwrite($connection, "HTTP/1.1 404 Not Found\r\nContent-Type: text/plain\r\nContent-Length: 0\r\n\r\n");
        }
    }
}
fwrite(STDERR, "No stop request arrived.\n");
exit(1);

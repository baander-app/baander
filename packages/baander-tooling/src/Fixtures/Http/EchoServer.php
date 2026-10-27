<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Http;

use Baander\Tooling\Process\SignalScope;
use RuntimeException;

final class EchoServer
{
    public static function run(string $readyFile, int $port = 9501): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:' . $port, $error, $message);
        if ($server === false) {
            throw new RuntimeException('Cannot bind HTTP echo fixture: ' . $message);
        }
        stream_set_blocking($server, false);
        $address = (string) stream_socket_get_name($server, false);
        $signals = new SignalScope();
        /** @var array<int,array{stream:resource,buffer:string,deadline:float}> $clients */
        $clients = [];
        file_put_contents(
            $readyFile,
            json_encode([
                'port' => (int) substr($address, (int) strrpos($address, ':') + 1),
            ], JSON_THROW_ON_ERROR) . "\n",
        );
        try {
            while (true) {
                $signals->dispatch();
                if ($signals->receivedSignal() !== null) {
                    break;
                }
                $now = hrtime(true) / 1e9;
                foreach ($clients as $identifier => $client) {
                    if ($client['deadline'] <= $now) {
                        fclose($client['stream']);
                        unset($clients[$identifier]);
                    }
                }
                $read = [$server, ...array_column($clients, 'stream')];
                $write = $except = [];
                if (@stream_select($read, $write, $except, 0, 20_000) === false) {
                    continue;
                }
                foreach ($read as $client) {
                    if ($client === $server) {
                        $connection = stream_socket_accept($server, 0);
                        if ($connection !== false) {
                            stream_set_blocking($connection, false);
                            $clients[(int) $connection] = [
                                'stream' => $connection,
                                'buffer' => '',
                                'deadline' => $now + 2,
                            ];
                        }
                        continue;
                    }
                    $identifier = (int) $client;
                    $chunk = fread($client, 8192);
                    if ($chunk === false || ($chunk === '' && feof($client))) {
                        fclose($client);
                        unset($clients[$identifier]);
                        continue;
                    }
                    $clients[$identifier]['buffer'] .= $chunk;
                    $request = $clients[$identifier]['buffer'];
                    if (!str_contains($request, "\r\n\r\n") && strlen($request) <= 65_536) {
                        continue;
                    }
                    try {
                        $response = self::response($request);
                        stream_set_blocking($client, true);
                        stream_set_timeout($client, 2);
                        while ($response !== '') {
                            $written = fwrite($client, $response);
                            if ($written === false || $written === 0) {
                                break;
                            }
                            $response = substr($response, $written);
                        }
                    } finally {
                        fclose($client);
                        unset($clients[$identifier]);
                    }
                }
            }
        } finally {
            foreach ($clients as $client) {
                fclose($client['stream']);
            }
            fclose($server);
            $signals->close();
        }
    }

    public static function response(string $request): string
    {
        $lines = explode("\r\n", $request);
        $first = array_shift($lines);
        if (!preg_match('/^GET (\S+) HTTP\/1\.[01]$/D', $first, $match)) {
            return "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
        }
        $headers = $lower = [];
        foreach ($lines as $line) {
            if ($line === '') {
                break;
            }
            $pair = explode(':', $line, 2);
            if (count($pair) !== 2) {
                continue;
            }
            $name = trim($pair[0]);
            $headers[$name] = trim($pair[1]);
            $lower[strtolower($name)] = trim($pair[1]);
        }
        if (strtolower($lower['upgrade'] ?? '') === 'websocket' && isset($lower['sec-websocket-key'])) {
            $accept = sha1($lower['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true) |> base64_encode(...);
            return "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: "
                . $accept
                . "\r\n\r\n";
        }
        $body = json_encode(['path' => $match[1], 'headers' => $headers], JSON_THROW_ON_ERROR);
        return "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body)
            . "\r\nConnection: close\r\n\r\n" . $body;
    }
}

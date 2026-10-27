<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

final class ObserverServer
{
    public static function run(string $directory, int $node, int $upstreamPort, int $port): void
    {
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        \Swoole\Coroutine\run(static function () use ($directory, $node, $upstreamPort, $port): void {
            $context = stream_context_create(['ssl' => [
                'local_cert' => "{$directory}/node-{$node}.crt",
                'local_pk' => "{$directory}/node-{$node}.key",
                'cafile' => "{$directory}/ca.crt",
                'verify_peer' => true,
                'verify_peer_name' => false,
            ]]);
            $listener = stream_socket_server(
                "tcp://127.0.0.1:{$port}",
                $errno,
                $error,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                $context,
            );
            Runtime::requireThat($listener !== false, $error);
            file_put_contents("{$directory}/observer.ready", 'ready');
            try {
                while (($client = @stream_socket_accept($listener, -1)) !== false) {
                    \Swoole\Coroutine::create(static function () use ($client, $directory, $node, $upstreamPort): void {
                        $upstream = null;
                        try {
                            stream_set_timeout($client, 4);
                            if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                                return;
                            }
                            $upstream = Http::connect(
                                "node-{$node}.rqlite.baander.app",
                                '127.0.0.1',
                                $upstreamPort,
                                [
                                    'cafile' => "{$directory}/ca.crt",
                                    'local_cert' => "{$directory}/client.crt",
                                    'local_pk' => "{$directory}/client.key",
                                ],
                                4,
                            );
                            while (($request = fgets($client)) !== false) {
                                if (!preg_match('/^POST (\S+) HTTP\/1\.[01]\r\n$/D', $request, $match)) {
                                    break;
                                }
                                $headers = [];
                                while (($line = fgets($client)) !== false && $line !== "\r\n") {
                                    [$name, $value] = explode(':', $line, 2);
                                    $headers[strtolower($name)] = trim($value);
                                }
                                $length = (int) ($headers['content-length'] ?? 0);
                                Runtime::requireThat($length <= 1048576, 'Observer request exceeds bound.');
                                $body = '';
                                while (strlen($body) < $length) {
                                    $part = fread($client, $length - strlen($body));
                                    if ($part === false || $part === '') {
                                        return;
                                    }
                                    $body .= $part;
                                }
                                [$status, $payload] = Http::exchange(
                                    $upstream,
                                    "node-{$node}.rqlite.baander.app",
                                    'POST',
                                    $match[1],
                                    $body,
                                    ['Content-Type' => 'application/json', 'Authorization' => $headers['authorization'] ?? ''],
                                    4,
                                );
                                $decoded = json_decode($payload, true);
                                $failed = !is_array($decoded)
                                    || isset($decoded['error'])
                                    || array_any($decoded['results'] ?? [], static fn($result) => isset($result['error']));
                                file_put_contents(
                                    "{$directory}/observations.jsonl",
                                    json_encode(['status' => $status, 'error' => $failed], JSON_THROW_ON_ERROR) . "\n",
                                    FILE_APPEND | LOCK_EX,
                                );
                                $wire = "HTTP/1.1 {$status} Result\r\nContent-Length: " . strlen($payload) . "\r\n\r\n" . $payload;
                                while ($wire !== '') {
                                    $written = fwrite($client, $wire);
                                    if ($written === false || $written === 0) {
                                        return;
                                    }
                                    $wire = substr($wire, $written);
                                }
                            }
                        } catch (\Throwable) {
                        } finally {
                            if (is_resource($upstream)) {
                                fclose($upstream);
                            }
                            fclose($client);
                        }
                    });
                }
            } finally {
                fclose($listener);
            }
        });
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Baander\RegistryTests;

final class TransportFixture
{
    public static function serve(string $configPath): void
    {
        $config = json_decode(file_get_contents($configPath), true, flags: JSON_THROW_ON_ERROR);
        $context = stream_context_create(['ssl' => [
            'local_cert' => $config['certificate'],
            'local_pk' => $config['key'],
            'cafile' => $config['ca'],
            'verify_peer' => true,
            'verify_peer_name' => false,
            'allow_self_signed' => false,
            'capture_peer_cert' => true,
            'ciphers' => $config['legacy'] ? 'DEFAULT:@SECLEVEL=0' : 'DEFAULT',
            'security_level' => $config['legacy'] ? 0 : 2,
        ]]);
        $server = stream_socket_server(
            'tcp://127.0.0.1:' . $config['port'],
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );
        if (!$server) {
            throw new \RuntimeException($error);
        }
        stream_set_blocking($server, false);
        file_put_contents($config['ready'], 'ready');
        $clients = [];
        $buffers = [];
        $pending = [];
        while (!is_file($config['ready'] . '.stop')) {
            $read = [$server, ...array_values($clients)];
            $write = [];
            $except = [];
            @stream_select($read, $write, $except, 0, 10000);
            foreach ($read as $stream) {
                if ($stream === $server) {
                    $client = @stream_socket_accept($server, 0);
                    if (!$client) {
                        continue;
                    }
                    stream_set_timeout($client, 1);
                    $peek = '';
                    $socket = socket_import_stream($client);
                    @socket_recv($socket, $peek, 8192, MSG_PEEK);
                    $name = self::serverName($peek);
                    file_put_contents(
                        $config['events'],
                        json_encode(['type' => 'handshake', 'name' => $name]) . "\n",
                        FILE_APPEND | LOCK_EX,
                    );
                    $method = $config['legacy'] ? STREAM_CRYPTO_METHOD_TLSv1_1_SERVER : STREAM_CRYPTO_METHOD_TLS_SERVER;
                    if (!@stream_socket_enable_crypto($client, true, $method)) {
                        fclose($client);
                        continue;
                    }
                    stream_set_blocking($client, false);
                    $id = (int) $client;
                    $clients[$id] = $client;
                    $buffers[$id] = '';
                    continue;
                }
                $id = (int) $stream;
                $part = @fread($stream, 65536);
                if ($part === false || $part === '' && feof($stream)) {
                    fclose($stream);
                    unset($clients[$id], $buffers[$id], $pending[$id]);
                    continue;
                }
                $buffers[$id] .= $part;
                if (isset($pending[$id]) || !str_contains($buffers[$id], "\r\n\r\n")) {
                    continue;
                }
                [$header, $body] = explode("\r\n\r\n", $buffers[$id], 2);
                preg_match('/Content-Length:\s*(\d+)/i', $header, $length);
                if (strlen($body) < (int) ($length[1] ?? 0)) {
                    continue;
                }
                preg_match('{^POST (\S+)}', $header, $target);
                preg_match('/Authorization:\s*([^\r\n]+)/i', $header, $authorization);
                $peer = stream_context_get_options($stream)['ssl']['peer_certificate'] ?? null;
                file_put_contents(
                    $config['events'],
                    json_encode([
                        'type' => 'request',
                        'port' => $config['port'],
                        'target' => $target[1] ?? '',
                        'authorization' => $authorization[1] ?? null,
                        'peer' => $peer !== null,
                    ]) . "\n",
                    FILE_APPEND | LOCK_EX,
                );
                $stateHandle = fopen($config['state'], 'r+');
                flock($stateHandle, LOCK_EX);
                $state = json_decode(stream_get_contents($stateHandle), true, flags: JSON_THROW_ON_ERROR);
                if ($state['delayOnce']) {
                    $next = $state;
                    $next['delay'] = 0;
                    $next['delayOnce'] = false;
                    rewind($stateHandle);
                    ftruncate($stateHandle, 0);
                    fwrite($stateHandle, json_encode($next));
                    fflush($stateHandle);
                }
                flock($stateHandle, LOCK_UN);
                fclose($stateHandle);
                $pending[$id] = ['at' => microtime(true) + $state['delay'], 'state' => $state];
                $buffers[$id] = '';
            }
            foreach ($pending as $id => $response) {
                if ($response['at'] > microtime(true)) {
                    continue;
                }
                $state = $response['state'];
                $body = $state['body'];
                $wire = 'HTTP/1.1 ' . $state['status'] . " Fixture\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
                @fwrite($clients[$id], $wire);
                unset($pending[$id]);
            }
        }
    }
    private static function serverName(string $hello): ?string
    {
        // TLS ClientHello: fixed header, session id, cipher suites, compression, extensions.
        if (strlen($hello) < 44) {
            return null;
        }
        $offset = 43;
        $offset += 1 + ord($hello[$offset]);
        if (strlen($hello) < $offset + 2) {
            return null;
        }
        $offset += 2 + unpack('n', substr($hello, $offset, 2))[1];
        if (strlen($hello) < $offset + 1) {
            return null;
        }
        $offset += 1 + ord($hello[$offset]);
        $offset += 2;
        while (strlen($hello) >= $offset + 4) {
            $type = unpack('n', substr($hello, $offset, 2))[1];
            $size = unpack('n', substr($hello, $offset + 2, 2))[1];
            $offset += 4;
            if ($type === 0 && $size >= 5) {
                $length = unpack('n', substr($hello, $offset + 3, 2))[1];
                return substr($hello, $offset + 5, $length);
            }
            $offset += $size;
        }
        return null;
    }
}

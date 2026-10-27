<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

final class FaultNetworkServer
{
    /**
     * @param list<int> $realPorts */
    public static function run(string $directory, array $realPorts): void
    {
        $listeners = $links = [];
        $ports = [];
        $group = null;
        $blackhole = false;
        $generation = -1;
        $control = $directory . '/fault-control.json';
        $blocked = static fn(int $source, int $destination, ?array $members): bool => $members !== null
            && in_array($source, $members, true) !== in_array($destination, $members, true);
        try {
            foreach ($realPorts as $index => $_) {
                $context = stream_context_create(['ssl' => [
                    'local_cert' => "{$directory}/node-{$index}.crt",
                    'local_pk' => "{$directory}/node-{$index}.key",
                    'cafile' => "{$directory}/ca.crt",
                    'verify_peer' => true,
                    'verify_peer_name' => false,
                    'capture_peer_cert' => true,
                    'allow_self_signed' => false,
                ]]);
                $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
                Runtime::requireThat($socket !== false, $error);
                stream_set_blocking($socket, false);
                $listeners[$index] = $socket;
                $ports[] = Runtime::socketPort($socket);
            }
            file_put_contents($directory . '/fault-ready.json', json_encode($ports, JSON_THROW_ON_ERROR));
            for (;;) {
                $state = json_decode((string) file_get_contents($control), true, flags: JSON_THROW_ON_ERROR);
                if ($state['generation'] !== $generation) {
                    $previous = $group;
                    $group = $state['group'];
                    $blackhole = $state['blackhole'];
                    foreach ($links as $id => $link) {
                        if ($blocked($link['source'], $link['destination'], $group)
                            && !$blackhole
                            || $group === null
                            && $blocked($link['source'], $link['destination'], $previous)) {
                            fclose($link['incoming']);
                            fclose($link['outgoing']);
                            unset($links[$id]);
                        }
                    }
                    $generation = $state['generation'];
                    file_put_contents($control . '.ack', (string) $generation);
                }
                $read = array_values($listeners);
                $write = [];
                foreach ($links as $link) {
                    if ($blackhole && $blocked($link['source'], $link['destination'], $group)) {
                        continue;
                    }
                    if (strlen($link['toOutgoing']) < 65536) {
                        $read[] = $link['incoming'];
                    }
                    if (strlen($link['toIncoming']) < 65536) {
                        $read[] = $link['outgoing'];
                    }
                    if ($link['toOutgoing'] !== '') {
                        $write[] = $link['outgoing'];
                    }
                    if ($link['toIncoming'] !== '') {
                        $write[] = $link['incoming'];
                    }
                }
                $except = null;
                if (@stream_select($read, $write, $except, 0, 20000) === false) {
                    continue;
                }
                foreach ($listeners as $destination => $listener) {
                    if (!in_array($listener, $read, true)) {
                        continue;
                    }
                    $incoming = @stream_socket_accept($listener, 0);
                    if ($incoming === false) {
                        continue;
                    }
                    stream_set_timeout($incoming, 2);
                    stream_set_blocking($incoming, true);
                    if (@stream_socket_enable_crypto($incoming, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                        fclose($incoming);
                        continue;
                    }
                    $parameters = stream_context_get_params($incoming);
                    $certificate = openssl_x509_parse($parameters['options']['ssl']['peer_certificate']);
                    $cn = $certificate['subject']['CN'] ?? '';
                    if (!is_string($cn)
                        || !preg_match('/^node-([0-9]+)$/D', $cn, $match)
                        || (int) $match[1] >= count($realPorts)
                        || count($links) >= 256
                        || $blocked((int) $match[1], $destination, $group)) {
                        fclose($incoming);
                        continue;
                    }
                    $source = (int) $match[1];
                    $context = stream_context_create(['ssl' => [
                        'cafile' => "{$directory}/ca.crt",
                        'local_cert' => "{$directory}/node-{$source}.crt",
                        'local_pk' => "{$directory}/node-{$source}.key",
                        'peer_name' => 'raft.registry.baander.app',
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                    ]]);
                    $outgoing = @stream_socket_client(
                        'tls://127.0.0.1:' . $realPorts[$destination],
                        $errno,
                        $error,
                        2,
                        STREAM_CLIENT_CONNECT,
                        $context,
                    );
                    if ($outgoing === false) {
                        fclose($incoming);
                        continue;
                    }
                    stream_set_blocking($incoming, false);
                    stream_set_blocking($outgoing, false);
                    $links[(int) $incoming] = compact('incoming', 'outgoing', 'source', 'destination') + ['toIncoming' => '', 'toOutgoing' => ''];
                }
                foreach ($links as $id => &$link) {
                    if ($blackhole && $blocked($link['source'], $link['destination'], $group)) {
                        continue;
                    }
                    $closed = false;
                    foreach ([['incoming', 'toOutgoing'], ['outgoing', 'toIncoming']] as [$side, $buffer]) {
                        if (in_array($link[$side], $read, true)) {
                            $data = @fread($link[$side], 65536);
                            if ($data === false || $data === '' && feof($link[$side])) {
                                $closed = true;
                                break;
                            }
                            $link[$buffer] .= $data;
                        }
                    }
                    foreach ([['outgoing', 'toOutgoing'], ['incoming', 'toIncoming']] as [$side, $buffer]) {
                        if (in_array($link[$side], $write, true)) {
                            $written = @fwrite($link[$side], $link[$buffer]);
                            if ($written === false) {
                                $closed = true;
                                break;
                            }
                            $link[$buffer] = substr($link[$buffer], $written);
                        }
                    }
                    if ($closed) {
                        fclose($link['incoming']);
                        fclose($link['outgoing']);
                        unset($links[$id]);
                    }
                }
                unset($link);
            }
        } finally {
            foreach ($links as $link) {
                fclose($link['incoming']);
                fclose($link['outgoing']);
            }
            foreach ($listeners as $listener) {
                fclose($listener);
            }
        }
    }
}

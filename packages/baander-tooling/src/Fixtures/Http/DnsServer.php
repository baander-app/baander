<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Http;

use Baander\Tooling\Process\SignalScope;
use RuntimeException;

final class DnsServer
{
    /** @return array{packet:string,delay:float}|null */
    public static function response(string $packet, bool $tcp = false): ?array
    {
        if (strlen($packet) < 12) {
            return null;
        }
        $header = unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional', $packet);
        if ($header === false || $header['questions'] !== 1) {
            return null;
        }
        $offset = 12;
        $labels = [];
        while (true) {
            if (!isset($packet[$offset])) {
                return null;
            }
            $length = ord($packet[$offset++]);
            if ($length === 0) {
                break;
            }
            if ($length > 63 || $offset + $length > strlen($packet)) {
                return null;
            }
            $label = substr($packet, $offset, $length);
            if (preg_match('/^[\x00-\x7f]*$/D', $label) !== 1) {
                return null;
            }
            $labels[] = $label;
            $offset += $length;
        }
        if ($offset + 4 > strlen($packet)) {
            return null;
        }
        $query = unpack('nkind/nfamily', substr($packet, $offset, 4));
        if ($query === false) {
            return null;
        }
        $question = substr($packet, 12, $offset + 4 - 12);
        $host = implode('.', $labels) |> strtolower(...);
        $kind = $query['kind'];
        $addresses = [];
        $code = 0;
        $delay = 0.0;
        if (!str_ends_with($host, '.baander.app') || $query['family'] !== 1) {
            $code = 3;
        } elseif ($host === 'stall.baander.app' || ($host === 'orphan.baander.app' && $kind === 28)) {
            return null;
        } elseif ($host === 'family-error.baander.app' && $kind === 28) {
            $code = 2;
        } elseif ($kind === 1) {
            $addresses = $host === 'many.baander.app'
                ? [...array_map(static fn(int $last): string => '8.8.4.' . $last, range(1, 100)), '10.0.0.1']
                : ['8.8.8.8'];
            $delay = $host === 'orphan.baander.app' ? 0.3 : 0.0;
        } elseif ($kind === 28 && !in_array($host, ['nodata.baander.app', 'many.baander.app'], true)) {
            $addresses = [$host === 'private6.baander.app' ? 'fd00::1' : '2001:4860:4860::8888'];
        }
        $records = '';
        foreach ($addresses as $address) {
            $raw = inet_pton($address);
            if ($raw === false) {
                throw new RuntimeException('Invalid DNS fixture address');
            }
            $records .= "\xc0\x0c" . pack('nnNn', $kind, 1, 0, strlen($raw)) . $raw;
        }
        $reply = pack('n6', $header['id'], 0x8080 | ($header['flags'] & 0x0100) | $code, 1, count($addresses), 0, 0)
            . $question . $records;
        if (strlen($reply) > 512 && !$tcp) {
            $reply = pack('n6', $header['id'], 0x8280 | ($header['flags'] & 0x0100), 1, 0, 0, 0) . $question;
        }
        return ['packet' => $reply, 'delay' => $delay];
    }

    public static function run(string $readyFile, int $port = 53): void
    {
        $udp = @stream_socket_server('udp://127.0.0.1:' . $port, $error, $message, STREAM_SERVER_BIND);
        if ($udp === false) {
            throw new RuntimeException('Cannot bind UDP DNS fixture: ' . $message);
        }
        $address = stream_socket_get_name($udp, false);
        $actualPort = (int) substr((string) $address, (int) strrpos((string) $address, ':') + 1);
        $tcp = @stream_socket_server('tcp://127.0.0.1:' . $actualPort, $error, $message);
        if ($tcp === false) {
            fclose($udp);
            throw new RuntimeException('Cannot bind TCP DNS fixture: ' . $message);
        }
        stream_set_blocking($udp, false);
        stream_set_blocking($tcp, false);
        $signals = new SignalScope();
        /** @var array<int,array{stream:resource,buffer:string,until:float}> $clients */
        $clients = [];
        /** @var list<array{stream:resource,peer:?string,packet:string,due:float}> $pending */
        $pending = [];
        file_put_contents($readyFile, json_encode(['port' => $actualPort], JSON_THROW_ON_ERROR) . "\n");
        try {
            while (true) {
                $signals->dispatch();
                if ($signals->receivedSignal() !== null) {
                    break;
                }
                $now = hrtime(true) / 1e9;
                foreach ($pending as $index => $reply) {
                    if ($reply['due'] <= $now) {
                        if ($reply['peer'] !== null) {
                            stream_socket_sendto($reply['stream'], $reply['packet'], 0, $reply['peer']);
                        } else {
                            stream_set_blocking($reply['stream'], true);
                            stream_set_timeout($reply['stream'], 2);
                            fwrite($reply['stream'], $reply['packet']);
                            fclose($reply['stream']);
                        }
                        unset($pending[$index]);
                    }
                }
                $pending = array_values($pending);
                foreach ($clients as $identifier => $client) {
                    if ($client['until'] <= $now) {
                        fclose($client['stream']);
                        unset($clients[$identifier]);
                    }
                }
                $read = [$udp, $tcp, ...array_column($clients, 'stream')];
                $write = $except = [];
                if (@stream_select($read, $write, $except, 0, 20_000) === false) {
                    continue;
                }
                foreach ($read as $stream) {
                    if ($stream === $tcp) {
                        $connection = stream_socket_accept($tcp, 0);
                        if ($connection !== false) {
                            stream_set_blocking($connection, false);
                            $clients[(int) $connection] = [
                                'stream' => $connection,
                                'buffer' => '',
                                'until' => $now + 2,
                            ];
                        }
                        continue;
                    }
                    $peer = null;
                    if ($stream === $udp) {
                        $packet = stream_socket_recvfrom($udp, 65_535, 0, $peer);
                        if ($packet === false) {
                            continue;
                        }
                    } else {
                        $identifier = (int) $stream;
                        $chunk = fread($stream, 65_537);
                        if ($chunk === false || ($chunk === '' && feof($stream))) {
                            fclose($stream);
                            unset($clients[$identifier]);
                            continue;
                        }
                        $clients[$identifier]['buffer'] .= $chunk;
                        $buffer = $clients[$identifier]['buffer'];
                        if (strlen($buffer) < 2) {
                            continue;
                        }
                        $length = unpack('nlength', $buffer)['length'];
                        if (strlen($buffer) < $length + 2) {
                            continue;
                        }
                        $packet = substr($buffer, 2, $length);
                        unset($clients[$identifier]);
                    }
                    $reply = self::response($packet, $stream !== $udp);
                    if ($reply === null) {
                        if ($stream !== $udp) {
                            fclose($stream);
                        }
                        continue;
                    }
                    $pending[] = [
                        'stream' => $stream,
                        'peer' => $peer,
                        'packet' => $stream === $udp ? $reply['packet'] : pack('n', strlen($reply['packet'])) . $reply['packet'],
                        'due' => $now + $reply['delay'],
                    ];
                }
            }
        } finally {
            foreach ($clients as $client) {
                fclose($client['stream']);
            }
            foreach ($pending as $reply) {
                if ($reply['peer'] === null) {
                    fclose($reply['stream']);
                }
            }
            fclose($tcp);
            fclose($udp);
            $signals->close();
        }
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        $ready = null;
        $port = 53;
        while ($arguments !== []) {
            $option = array_shift($arguments);
            $value = array_shift($arguments);
            if ($value === null) {
                throw new \InvalidArgumentException('Missing value for ' . $option);
            }
            match ($option) {
                '--ready-file' => $ready = $value,
                '--port' => $port = filter_var($value, FILTER_VALIDATE_INT),
                default => throw new \InvalidArgumentException('Unknown DNS fixture option: ' . $option),
            };
        }
        if ($ready === null || $port === false || $port < 0 || $port > 65_535) {
            throw new \InvalidArgumentException('Usage: dns-server.php --ready-file PATH [--port PORT]');
        }
        self::run($ready, $port);
        return 0;
    }
}

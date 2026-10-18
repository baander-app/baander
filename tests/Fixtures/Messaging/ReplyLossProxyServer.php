<?php

declare(strict_types=1);

// Standalone fresh exec: no application bootstrap or inherited DBAL sessions.
$arguments = $_SERVER['argv'] ?? [];
if (PHP_SAPI !== 'cli' || count($arguments) !== 2 || !is_string($arguments[1]) || $arguments[1] === '') {
    throw new InvalidArgumentException('Expected one reply-loss proxy fixture path.');
}
$prefix = $arguments[1];
$config = json_decode((string) file_get_contents($prefix . '.config'), true, flags: JSON_THROW_ON_ERROR);
$deadline = microtime(true) + 12;
$bytes = 0;

/** @param resource $socket */
$read = static function ($socket, int $length) use ($deadline, &$bytes): string {
    $result = '';
    while (strlen($result) < $length) {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0 || $bytes + $length > 1048576) {
            throw new RuntimeException('Reply-loss proxy deadline or byte limit exceeded.');
        }
        stream_set_timeout($socket, (int) $remaining, (int) (($remaining - (int) $remaining) * 1e6));
        $part = fread($socket, $length - strlen($result));
        if ($part === false || $part === '') {
            throw new RuntimeException('Reply-loss proxy received an incomplete frame.');
        }
        $bytes += strlen($part);
        $result .= $part;
    }
    return $result;
};

/** @param resource $socket */
$frame = static function ($socket, int $depth = 0) use (&$frame, $read): array {
    if ($depth > 16) {
        throw new RuntimeException('Reply-loss proxy nesting limit exceeded.');
    }
    $wire = $read($socket, 1);
    $type = $wire;
    do {
        $wire .= $read($socket, 1);
    } while (!str_ends_with($wire, "\r\n"));
    $value = substr($wire, 1, -2);
    if ($type === '$' && (int) $value >= 0) {
        $body = $read($socket, (int) $value + 2);
        if (!str_ends_with($body, "\r\n")) {
            throw new RuntimeException('Reply-loss proxy invalid bulk frame.');
        }
        return [$wire . $body, substr($body, 0, -2), $type];
    }
    if ($type === '*') {
        $values = [];
        for ($i = 0; $i < (int) $value; ++$i) {
            [$child, $decoded] = $frame($socket, $depth + 1);
            $wire .= $child;
            $values[] = $decoded;
        }
        return [$wire, $values, $type];
    }
    if (!in_array($type, ['+', '-', ':', '$'], true)) {
        throw new RuntimeException('Reply-loss proxy unsupported frame type.');
    }
    return [$wire, $type === ':' ? (int) $value : $value, $type];
};

/** @param resource $socket */
$write = static function ($socket, string $wire) use ($deadline): void {
    while ($wire !== '') {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Reply-loss proxy write deadline exceeded.');
        }
        stream_set_timeout($socket, 1);
        $sent = fwrite($socket, $wire);
        if ($sent === false || $sent === 0) {
            throw new RuntimeException('Reply-loss proxy write failed.');
        }
        $wire = substr($wire, $sent);
    }
};

$server = $client = $upstream = null;
try {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) {
        throw new RuntimeException('Reply-loss proxy could not listen.');
    }
    $address = stream_socket_get_name($server, false);
    file_put_contents($prefix . '.ready', substr($address, strrpos($address, ':') + 1));
    $client = stream_socket_accept($server, 5);
    $upstream = stream_socket_client('tcp://' . $config['host'] . ':' . $config['port'], $errno, $error, 2);
    if ($client === false || $upstream === false) {
        throw new RuntimeException('Reply-loss proxy connection failed.');
    }
    for ($commands = 0; $commands < 100; ++$commands) {
        [$wire, $arguments] = $frame($client);
        $target = strtoupper($arguments[0]) === $config['command']
            && ($config['command'] !== 'XREADGROUP' || end($arguments) === '>');
        $slot = $target ? json_decode((string) file_get_contents($config['ledger']), true, flags: JSON_THROW_ON_ERROR)['slots']['consumer-0'] : null;
        $write($upstream, $wire);
        [$reply, $value, $type] = $frame($upstream);
        if ($target) {
            $success = $config['command'] === 'XACK' ? $type === ':' && $value === 1
                : $type === '*' && isset($value[0][1][0][0]);
            if (!$success) {
                throw new RuntimeException('Target Redis command did not return successful work.');
            }
            file_put_contents($prefix . '.receipt', json_encode([
                'command' => $arguments, 'response' => $value, 'slot' => $slot,
            ], JSON_THROW_ON_ERROR));
            return; // Close both sockets without forwarding the complete reply.
        }
        $write($client, $reply);
    }
    throw new RuntimeException('Reply-loss proxy command limit exceeded.');
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    foreach ([$client, $upstream, $server] as $socket) {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\WebRuntime;

use RuntimeException;

/**
 * One HTTP/1.1 connection to the web server that stays open between requests.
 *
 * The server dispatches by connection (`dispatch_mode: 2`): every request on a connection
 * runs in the worker its descriptor maps to. A client that reconnects by itself would move
 * to another worker unnoticed, so this one never reconnects: the next request on a
 * connection the server closed fails.
 */
final class HeldConnection
{
    /** @var resource */
    private $socket;

    public function __construct(string $address = 'tcp://127.0.0.1:9501', float $timeoutSeconds = 10.0)
    {
        $socket = stream_socket_client($address, $errno, $error, $timeoutSeconds);
        if ($socket === false) {
            throw new RuntimeException(sprintf('Cannot connect to %s: %s', $address, $error));
        }
        $seconds = (int) $timeoutSeconds;
        stream_set_timeout($socket, $seconds, (int) (($timeoutSeconds - $seconds) * 1e6));
        $this->socket = $socket;
    }

    /**
     * Writes a request without a body and returns before the answer, so one caller can have
     * requests in flight on several connections.
     *
     * @param list<string> $headers header lines
     */
    public function send(string $method, string $path, array $headers = []): void
    {
        $lines = [$method . ' ' . $path . ' HTTP/1.1', 'Host: 127.0.0.1:9501', 'Accept: application/json', ...$headers];
        if ($method !== 'GET') {
            $lines[] = 'Content-Length: 0';
        }
        $request = implode("\r\n", $lines) . "\r\n\r\n";
        if (fwrite($this->socket, $request) !== strlen($request)) {
            throw new RuntimeException(sprintf('Could not send %s %s on a held connection.', $method, $path));
        }
    }

    /** @return array{status: int, body: string} */
    public function receive(): array
    {
        $status = $this->line();
        if (preg_match('/^HTTP\/1\.1 ([0-9]{3})(?: |$)/', $status, $matches) !== 1) {
            throw new RuntimeException('Not an HTTP/1.1 status line: ' . $status);
        }
        $length = null;
        while (($header = $this->line()) !== '') {
            if (preg_match('/^Content-Length: *([0-9]+)$/i', $header, $field) === 1) {
                $length = (int) $field[1];
            }
        }
        if ($length === null) {
            throw new RuntimeException('The answer to a request on a held connection carries no Content-Length.');
        }

        return ['status' => (int) $matches[1], 'body' => $length === 0 ? '' : $this->bytes($length)];
    }

    /**
     * @param list<string> $headers header lines
     *
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $path, array $headers = []): array
    {
        $this->send($method, $path, $headers);

        return $this->receive();
    }

    private function line(): string
    {
        $line = fgets($this->socket);
        if ($line === false) {
            throw new RuntimeException($this->ended());
        }

        return rtrim($line, "\r\n");
    }

    private function bytes(int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($this->socket, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException($this->ended());
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    private function ended(): string
    {
        return stream_get_meta_data($this->socket)['timed_out']
            ? 'The server did not answer on a held connection in time.'
            : 'The server closed a held connection.';
    }
}

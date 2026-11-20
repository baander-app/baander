<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer\Agent;

/** A private collector transport; request paths never originate in the read protocol. */
final readonly class DockerClient
{
    public function __construct(private string $socket, private float $timeout = 5.0)
    {
    }

    public static function timestampCursor(string $timestamp): string
    {
        $seconds = new \DateTimeImmutable($timestamp)->format('U');
        preg_match('/\.(\d{1,9})/', $timestamp, $fraction);

        return $seconds.'.'.str_pad($fraction[1] ?? '', 9, '0');
    }

    /** @return array<mixed> */
    public function json(string $path): array
    {
        return json_decode($this->read($path, 8388608), true, 64, JSON_THROW_ON_ERROR);
    }

    public function read(string $path, int $limit = 16777216): string
    {
        $body = '';
        foreach ($this->chunks($path) as $chunk) {
            $body .= $chunk;
            if (strlen($body) > $limit) {
                throw new \RuntimeException('Docker collection exceeded byte bound; coverage is incomplete.');
            }
        }

        return $body;
    }

    /** @return \Generator<int,string> */
    public function chunks(string $path): \Generator
    {
        $stream = @stream_socket_client('unix://'.$this->socket, $errno, $error, $this->timeout);
        if (false === $stream) {
            throw new \RuntimeException('Docker daemon unavailable.');
        }
        try {
            stream_set_timeout($stream, (int) ceil($this->timeout));
            fwrite($stream, "GET /v1.45".$path." HTTP/1.0\r\nHost: docker\r\nConnection: close\r\n\r\n");
            $status = fgets($stream, 4096);
            if (false === $status || !preg_match('~^HTTP/1\.[01] 200 ~', $status)) {
                throw new \RuntimeException('Docker collection request failed.');
            }
            $headerBytes = 0;
            while (false !== ($line = fgets($stream, 4096)) && "\r\n" !== $line) {
                $headerBytes += strlen($line);
                if ($headerBytes > 65536) {
                    throw new \RuntimeException('Docker response headers exceed bound.');
                }
                if (str_starts_with(strtolower($line), 'transfer-encoding:')) {
                    throw new \RuntimeException('Unexpected transfer encoding from Docker.');
                }
            }
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if (false === $chunk || stream_get_meta_data($stream)['timed_out']) {
                    throw new \RuntimeException('Docker collection exceeded read deadline; coverage is incomplete.');
                }
                if ('' !== $chunk) {
                    yield $chunk;
                }
            }
        } finally {
            fclose($stream);
        }
    }
}

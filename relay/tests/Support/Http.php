<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Baander\RegistryTests;

final class Http
{
    /**
     * @param array<string,mixed> $tls
     * @return resource
     */
    public static function connect(string $host, string $address, int $port, array $tls, float $timeout = 3)
    {
        $bind = $tls['bindto'] ?? null;
        unset($tls['bindto']);
        $options = $tls === [] ? [] : ['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
            ...$tls,
        ]];
        $options['socket'] = $bind === null ? [] : ['bindto' => $bind];
        $stream = @stream_socket_client(
            'tcp://' . $address . ':' . $port,
            $error,
            $message,
            $timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create($options),
        );
        if ($stream === false) {
            throw new \RuntimeException('HTTP connection failed: ' . $message);
        }
        stream_set_timeout($stream, (int) $timeout, (int) (($timeout - (int) $timeout) * 1000000));
        if ($tls !== [] && !@stream_socket_enable_crypto($stream, true, $tls['crypto_method'] ?? STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($stream);
            throw new \RuntimeException('TLS handshake failed');
        }
        return $stream;
    }
    /**
     * @param array<string,mixed> $tls
     * @param array<string,string> $headers
     * @return array{int,string,array<string,string>}
     */
    public static function request(
        string $host,
        string $address,
        int $port,
        array $tls,
        string $method,
        string $target,
        ?string $body = null,
        array $headers = [],
        float $timeout = 3,
        int $maxBytes = 1048576,
    ): array {
        $stream = self::connect($host, $address, $port, $tls, $timeout);
        try {
            return self::exchange($stream, $host, $method, $target, $body, ['Connection' => 'close', ...$headers], $timeout, $maxBytes);
        } finally {
            fclose($stream);
        }
    }
    /**
     * @param resource $stream
     * @param array<string,string> $headers
     * @return array{int,string,array<string,string>}
     */
    public static function exchange($stream, string $host, string $method, string $target, ?string $body = null, array $headers = [], float $timeout = 3, int $maxBytes = 1048576): array
    {
        stream_set_timeout($stream, (int) $timeout, (int) (($timeout - (int) $timeout) * 1000000));
        $headers = ['Host' => $host, ...$headers];
        if ($body !== null) {
            $headers['Content-Length'] = (string) strlen($body);
        }
        $request = $method . ' ' . $target . " HTTP/1.1\r\n";
        foreach ($headers as $name => $value) {
            $request .= $name . ': ' . $value . "\r\n";
        }
        $request .= "\r\n" . ($body ?? '');
        while ($request !== '') {
            $written = @fwrite($stream, $request);
            if (!$written) {
                throw new \RuntimeException('HTTP write failed');
            }
            $request = substr($request, $written);
        }
        $head = '';
        while (!str_ends_with($head, "\r\n\r\n")) {
            $head .= self::read($stream, 1);
            if (strlen($head) > min($maxBytes, 65536)) {
                throw new \RuntimeException('HTTP headers exceeded bound');
            }
        }
        $lines = explode("\r\n", rtrim($head, "\r\n"));
        if (!preg_match('{^HTTP/\d\.\d (\d{3})}', array_shift($lines), $match)) {
            throw new \RuntimeException('Invalid HTTP status');
        }
        $responseHeaders = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower($key)] = trim($value);
            }
        }
        $status = (int) $match[1];
        $raw = '';
        if ($method === 'HEAD' || $status === 204 || $status === 304 || $status < 200) {
            return [$status, $raw, $responseHeaders];
        }
        if (strtolower($responseHeaders['transfer-encoding'] ?? '') === 'chunked') {
            while (true) {
                $line = '';
                while (!str_ends_with($line, "\r\n")) {
                    $line .= self::read($stream, 1);
                    if (strlen($line) > 1024) {
                        throw new \RuntimeException('Invalid HTTP chunk');
                    }
                }
                $length = hexdec(explode(';', trim($line))[0]);
                if ($length === 0) {
                    while (true) {
                        $trailer = '';
                        while (!str_ends_with($trailer, "\r\n")) {
                            $trailer .= self::read($stream, 1);
                        }
                        if ($trailer === "\r\n") {
                            break;
                        }
                    }
                    break;
                }
                if (strlen($raw) + $length > $maxBytes) {
                    throw new \RuntimeException('HTTP response exceeded bound');
                }
                $raw .= self::read($stream, $length);
                Runtime::requireThat(self::read($stream, 2) === "\r\n", 'Invalid HTTP chunk delimiter');
            }
        } elseif (isset($responseHeaders['content-length'])) {
            $length = (int) $responseHeaders['content-length'];
            if ($length < 0 || $length > $maxBytes) {
                throw new \RuntimeException('HTTP response exceeded bound');
            }
            $raw = self::read($stream, $length);
        } else {
            while (!feof($stream)) {
                $part = @fread($stream, 8192);
                if ($part === false || $part === '' && stream_get_meta_data($stream)['timed_out']) {
                    throw new \RuntimeException('HTTP read timeout');
                }
                $raw .= $part;
                if (strlen($raw) > $maxBytes) {
                    throw new \RuntimeException('HTTP response exceeded bound');
                }
            }
        }
        return [$status, $raw, $responseHeaders];
    }
    /** @param resource $stream */
    private static function read($stream, int $length): string
    {
        $value = '';
        while (strlen($value) < $length) {
            $part = @fread($stream, $length - strlen($value));
            if ($part === false || $part === '') {
                throw new \RuntimeException(stream_get_meta_data($stream)['timed_out'] ? 'HTTP read timeout' : 'Incomplete HTTP response');
            }
            $value .= $part;
        }
        return $value;
    }
}

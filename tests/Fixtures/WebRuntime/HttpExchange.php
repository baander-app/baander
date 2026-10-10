<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\WebRuntime;

use CurlHandle;
use CurlMultiHandle;
use RuntimeException;

/**
 * One HTTP exchange driven step by step, so the web runtime fixture can write to a
 * rendition between the bytes the server sends.
 */
final class HttpExchange
{
    /** @var list<string> status line and header lines, in the order the server sent them */
    public array $headerLines = [];
    public string $body = '';
    public bool $done = false;
    private bool $stopped = false;
    private readonly CurlMultiHandle $multi;
    private readonly CurlHandle $handle;

    /**
     * @param list<string> $headers
     * @param int|null $stopAfterBytes drop the connection once this many body bytes arrived
     */
    public function __construct(
        string $url,
        array $headers,
        string $method = 'GET',
        ?string $payload = null,
        private readonly ?int $stopAfterBytes = null,
    ) {
        $this->handle = curl_init($url);
        curl_setopt_array($this->handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => function (CurlHandle $handle, string $line): int {
                $trimmed = rtrim($line, "\r\n");
                if ($trimmed !== '') {
                    $this->headerLines[] = $trimmed;
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function (CurlHandle $handle, string $data): int {
                $this->body .= $data;
                if ($this->stopAfterBytes !== null && strlen($this->body) >= $this->stopAfterBytes) {
                    // Accepting fewer bytes than received makes curl drop the connection.
                    $this->stopped = true;

                    return 0;
                }

                return strlen($data);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($this->handle, CURLOPT_POSTFIELDS, $payload);
        }
        $this->multi = curl_multi_init();
        curl_multi_add_handle($this->multi, $this->handle);
    }

    /**
     * Drive the exchange until $until holds; false when it ends or $seconds pass first.
     *
     * @param callable(self): bool $until
     */
    public function until(callable $until, float $seconds = 15.0): bool
    {
        $deadline = microtime(true) + $seconds;
        while (!$until($this)) {
            if ($this->done || microtime(true) >= $deadline) {
                return false;
            }
            curl_multi_exec($this->multi, $running);
            if ($running === 0) {
                $this->finish();
                continue;
            }
            curl_multi_select($this->multi, 0.05);
        }

        return true;
    }

    public function complete(float $seconds = 30.0): self
    {
        if (!$this->until(static fn (self $exchange): bool => $exchange->done, $seconds)) {
            throw new RuntimeException('The request did not finish in time.');
        }

        return $this;
    }

    public function status(): int
    {
        return (int) curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headerLines as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return null;
    }

    private function finish(): void
    {
        $info = curl_multi_info_read($this->multi);
        $result = is_array($info) ? $info['result'] : CURLE_OK;
        if ($result !== CURLE_OK && !($this->stopped && $result === CURLE_WRITE_ERROR)) {
            throw new RuntimeException('Request failed: ' . curl_strerror($result));
        }
        curl_multi_remove_handle($this->multi, $this->handle);
        $this->done = true;
    }
}

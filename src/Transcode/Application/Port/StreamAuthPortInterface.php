<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

interface StreamAuthPortInterface
{
    /**
     * Generate a signed URL for a stream resource.
     *
     * @param string $path The resource path (e.g., '/api/stream/abc123/seg_0.m4s')
     * @param int $expiresInSeconds URL validity window (default 86400 = 24h)
     * @return array{url: string, sig: string, exp: int}
     */
    public function signUrl(string $path, int $expiresInSeconds = 86400): array;

    /**
     * Validate a signed URL's signature and expiry.
     *
     * @param string $path The original resource path
     * @param string $signature The signature from the 'sig' query parameter
     * @param int $expiresAt The expiry timestamp from the 'exp' query parameter
     * @param string|null $clientIp Optional client IP for IP binding
     */
    public function validateUrl(string $path, string $signature, int $expiresAt, ?string $clientIp = null): bool;
}

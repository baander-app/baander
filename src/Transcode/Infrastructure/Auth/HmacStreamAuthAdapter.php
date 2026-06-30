<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Auth;

use App\Transcode\Application\Port\StreamAuthPortInterface;

/**
 * HMAC-SHA256 signed URL adapter for stream segment delivery.
 *
 * Generates and validates time-limited signed URLs using HMAC-SHA256.
 * Signature payload: "{path}:{expiresAt}[:{clientIp}]".
 * Supports optional IP binding for enhanced security.
 */
final readonly class HmacStreamAuthAdapter implements StreamAuthPortInterface
{
    public function __construct(
        private string $hmacSecret,
        private string $appDomain = '',
    ) {
    }

    public function signUrl(string $path, int $expiresInSeconds = 86400): array
    {
        $expiresAt = time() + $expiresInSeconds;
        $payload = $this->buildPayload($path, $expiresAt);
        $signature = $this->computeSignature($payload);

        $separator = str_contains($path, '?') ? '&' : '?';
        $url = sprintf('%s%ssig=%s&exp=%d', $path, $separator, $signature, $expiresAt);

        if ($this->appDomain !== '') {
            $url = rtrim($this->appDomain, '/') . $url;
        }

        return [
            'url' => $url,
            'sig' => $signature,
            'exp' => $expiresAt,
        ];
    }

    public function validateUrl(string $path, string $signature, int $expiresAt, ?string $clientIp = null): bool
    {
        // Check expiry first (cheapest check)
        if ($expiresAt < time()) {
            return false;
        }

        // Verify signature with timing-safe comparison
        $payload = $this->buildPayload($path, $expiresAt, $clientIp);
        $expected = $this->computeSignature($payload);

        return hash_equals($expected, $signature);
    }

    private function buildPayload(string $path, int $expiresAt, ?string $clientIp = null): string
    {
        $payload = $path . ':' . $expiresAt;

        if ($clientIp !== null) {
            $payload .= ':' . $clientIp;
        }

        return $payload;
    }

    private function computeSignature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->hmacSecret);
    }
}

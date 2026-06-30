<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Webhook;

final class HmacSigner
{
    public function sign(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    public function verify(string $payload, string $signature, string $secret): bool
    {
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    public function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }
}

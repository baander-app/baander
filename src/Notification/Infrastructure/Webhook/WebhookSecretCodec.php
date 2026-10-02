<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Webhook;

use App\Notification\Application\Port\WebhookSecretPortInterface;
use Defuse\Crypto\Crypto;

final class WebhookSecretCodec implements WebhookSecretPortInterface
{
    public function __construct(private readonly string $applicationSecret)
    {
        if ($applicationSecret === '') {
            throw new \InvalidArgumentException('APP_SECRET is required to protect webhook secrets.');
        }
    }

    public function encrypt(string $secret): string
    {
        return Crypto::encryptWithPassword($secret, $this->applicationSecret);
    }

    public function decrypt(string $encryptedSecret): string
    {
        return Crypto::decryptWithPassword($encryptedSecret, $this->applicationSecret);
    }
}

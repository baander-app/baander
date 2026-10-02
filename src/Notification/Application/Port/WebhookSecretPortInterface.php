<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

interface WebhookSecretPortInterface
{
    public function encrypt(string $secret): string;
    public function decrypt(string $encryptedSecret): string;
}

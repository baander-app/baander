<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

/**
 * A webhook with the plain signing secret just issued for it. Only the use case that issues
 * the secret returns it; storage keeps it encrypted, and nothing reads it back in plain.
 */
final readonly class IssuedWebhookSecret
{
    public function __construct(
        public WebhookView $webhook,
        public string $secret,
    ) {
    }
}

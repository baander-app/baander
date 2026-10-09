<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

/**
 * Replaces a webhook's signing secret. The old secret stops working at once; the webhook
 * keeps its signing version.
 */
final readonly class RotateWebhookSecretCommand
{
    public function __construct(
        public string $webhookId,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

/**
 * Deletes a webhook. Nothing is delivered to it afterwards.
 */
final readonly class DeleteWebhookCommand
{
    public function __construct(
        public string $webhookId,
    ) {
    }
}

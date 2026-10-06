<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

/** Identifies a durable delivery without replacing its original message. */
final readonly class DeliveryIntent
{
    /** @var 'email'|'push'|'webhook' */
    public string $channel;

    public function __construct(
        string $channel,
        public string $notificationId,
    ) {
        if (!in_array($channel, ['email', 'push', 'webhook'], true)) {
            throw new \InvalidArgumentException('Unsupported notification delivery channel.');
        }
        $this->channel = $channel;
        if (trim($notificationId) === '' || strlen($notificationId) > 64) {
            throw new \InvalidArgumentException('Notification delivery requires a notification ID of 1 to 64 characters.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Messaging;

use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendWebhookCommand;
use App\Shared\Application\DTO\DeliveryIntent;
use App\Shared\Application\Port\DeliveryIntentResolverInterface;

final readonly class NotificationDeliveryIntentResolver implements DeliveryIntentResolverInterface
{
    public function resolve(object $message): DeliveryIntent
    {
        $channel = match (true) {
            $message instanceof SendEmailCommand => 'email',
            $message instanceof SendPushCommand => 'push',
            $message instanceof SendWebhookCommand => 'webhook',
            default => throw new \InvalidArgumentException('Unsupported notification delivery message.'),
        };
        if ($message->notificationPublicId === null) {
            throw new \InvalidArgumentException('Notification delivery requires a notification ID of 1 to 64 characters.');
        }

        return new DeliveryIntent($channel, $message->notificationPublicId);
    }
}

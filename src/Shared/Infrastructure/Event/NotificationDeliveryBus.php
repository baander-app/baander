<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendWebhookCommand;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** Persists channel delivery intent on the caller's database transaction. */
final readonly class NotificationDeliveryBus implements MessageBusInterface
{
    public function __construct(
        private NotificationDeliveryRepository $repository,
        private JsonMessageCodec $codec,
    ) {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = Envelope::wrap($message);
        if ($stamps !== [] || $envelope->all() !== []) {
            throw new \InvalidArgumentException('Notification delivery intents do not support Messenger stamps.');
        }
        $message = $envelope->getMessage();
        $channel = self::channel($message);
        $notificationId = $message->notificationPublicId;
        if (!is_string($notificationId) || trim($notificationId) === '' || strlen($notificationId) > 64) {
            throw new \InvalidArgumentException('Notification delivery requires a notification ID of 1 to 64 characters.');
        }
        $this->repository->append($channel, $notificationId, $this->codec->encode($message));

        return $envelope;
    }

    public static function channel(object $message): string
    {
        return match (true) {
            $message instanceof SendEmailCommand => 'email',
            $message instanceof SendPushCommand => 'push',
            $message instanceof SendWebhookCommand => 'webhook',
            default => throw new \InvalidArgumentException('Unsupported notification delivery message.'),
        };
    }
}

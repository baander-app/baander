<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

use App\Shared\Application\Port\DeliveryIntentResolverInterface;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** Persists channel delivery intent on the caller's database transaction. */
final readonly class NotificationDeliveryBus implements MessageBusInterface
{
    public function __construct(
        private NotificationDeliveryRepository $repository,
        private JsonMessageCodec $codec,
        private DeliveryIntentResolverInterface $intentResolver,
    ) {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = Envelope::wrap($message);
        if ($stamps !== [] || $envelope->all() !== []) {
            throw new \InvalidArgumentException('Notification delivery intents do not support Messenger stamps.');
        }
        $message = $envelope->getMessage();
        $intent = $this->intentResolver->resolve($message);
        $this->repository->append($intent->channel, $intent->notificationId, $this->codec->encode($message));

        return $envelope;
    }
}

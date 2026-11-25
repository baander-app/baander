<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

/**
 * Keeps an envelope that did not decode out of the failure transport. The signing serializer
 * hands such an envelope to the worker as a decoding failure: unsigned, altered after signing,
 * or a body the codec refuses. Decoding it again cannot succeed, and storing it would let any
 * process that can write to Redis fill the failure table. The failure is unrecoverable, so
 * Symfony's retry listener logs it once and the worker rejects the delivery.
 */
final readonly class UndecodableDeliverySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // Before SendFailedMessageToFailureTransportListener (-100), which skips an envelope
        // already marked as sent to the failure transport from its receiver.
        return [WorkerMessageFailedEvent::class => ['onMessageFailed', 150]];
    }

    public static function undecodable(Envelope $envelope): bool
    {
        return $envelope->getMessage() instanceof MessageDecodingFailedException;
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if (self::undecodable($event->getEnvelope())) {
            $event->addStamps(new SentToFailureTransportStamp($event->getReceiverName()));
        }
    }
}

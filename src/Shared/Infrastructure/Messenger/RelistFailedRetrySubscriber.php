<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Returns a message whose retry from the failure transport failed again to that
 * transport, so it stays listed until an operator removes it.
 *
 * Symfony re-sends such a message only while the failure transport's retry strategy
 * allows it and discards it afterwards, or at once for an unrecoverable error. That
 * transport's strategy allows no retries, so every failed retry reaches this subscriber.
 */
final readonly class RelistFailedRetrySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SenderInterface $failureSender,
        private string $failureTransportName,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After AddErrorDetailsStampListener (200) has recorded the new error.
        return [WorkerMessageFailedEvent::class => ['onMessageFailed', -100]];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry() || $event->getReceiverName() !== $this->failureTransportName) {
            return;
        }

        $envelope = $event->getEnvelope();
        $this->failureSender->send($envelope->with(
            new DelayStamp(0),
            new RedeliveryStamp(RedeliveryStamp::getRetryCountFromEnvelope($envelope) + 1),
        ));
    }
}

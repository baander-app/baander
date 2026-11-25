<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

use App\Shared\Domain\Model\Uuid;

/** One notification for one push subscription, retried on its own when the push service fails. */
final readonly class SendPushToSubscriptionCommand
{
    public function __construct(
        public Uuid $subscriptionId,
        public Uuid $userId,
        public string $title,
        public string $body,
        public string $notificationPublicId,
    ) {
    }
}

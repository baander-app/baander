<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Domain\Model\Uuid;

final readonly class SendPushCommand
{
    public function __construct(
        public Uuid $userId,
        public NotificationCategory $category,
        public string $title,
        public string $body,
        public string $notificationPublicId,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Domain\Model\Uuid;

final readonly class SendEmailCommand
{
    public function __construct(
        public Uuid $userId,
        public string $userEmail,
        public NotificationCategory $category,
        public string $title,
        public string $body,
        public \DateTimeImmutable $createdAt,
        public ?string $notificationPublicId = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Domain\Model\Uuid;

/**
 * Carries message keys rather than text, so the email is written in the
 * recipient's language as it stands when the worker sends it.
 */
final readonly class SendEmailCommand
{
    /**
     * @param array<string, mixed> $titleParameters string, int or float values, or a TranslatableParameter
     * @param array<string, mixed> $bodyParameters  string, int or float values, or a TranslatableParameter
     */
    public function __construct(
        public Uuid $userId,
        public string $userEmail,
        public NotificationCategory $category,
        public string $titleKey,
        public array $titleParameters,
        public string $bodyKey,
        public array $bodyParameters,
        public \DateTimeImmutable $createdAt,
        public ?string $notificationPublicId = null,
    ) {
    }
}

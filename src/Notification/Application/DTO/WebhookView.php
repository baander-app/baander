<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

use App\Shared\Domain\Model\Uuid;

/**
 * A configured webhook as administration shows it. It never carries the signing secret.
 */
final readonly class WebhookView
{
    /**
     * @param list<string>|null $categoryFilter the notification categories delivered, or null for all
     * @param int               $signingVersion the signature scheme this webhook's deliveries use
     */
    public function __construct(
        public Uuid $id,
        public string $url,
        public ?array $categoryFilter,
        public int $signingVersion,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}

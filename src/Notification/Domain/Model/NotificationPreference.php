<?php

declare(strict_types=1);

namespace App\Notification\Domain\Model;

use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Domain\ValueObject\NotificationChannel;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class NotificationPreference
{
    private bool $enabled;

    private function __construct(
        private readonly Uuid $id,
        private readonly Uuid $userId,
        private readonly NotificationCategory $category,
        private readonly NotificationChannel $channel,
        bool $enabled,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        $this->enabled = $enabled;
    }

    /**
     * Create a new notification preference.
     */
    public static function create(
        Uuid $userId,
        NotificationCategory $category,
        NotificationChannel $channel,
        bool $enabled = true,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            Uuid::generate(),
            $userId,
            $category,
            $channel,
            $enabled,
            $now,
            $now,
        );
    }

    /**
     * Reconstitute a NotificationPreference from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(
        Uuid $id,
        Uuid $userId,
        NotificationCategory $category,
        NotificationChannel $channel,
        bool $enabled,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $userId,
            $category,
            $channel,
            $enabled,
            $createdAt,
            $updatedAt,
        );
    }

    public function enable(): void
    {
        if ($this->enabled) {
            return;
        }

        $this->enabled = true;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function disable(): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->enabled = false;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getCategory(): NotificationCategory
    {
        return $this->category;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

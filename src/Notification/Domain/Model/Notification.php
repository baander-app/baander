<?php

declare(strict_types=1);

namespace App\Notification\Domain\Model;

use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class Notification
{
    private bool $isRead;

    private function __construct(
        private readonly Uuid $id,
        private readonly PublicId $publicId,
        private readonly Uuid $userId,
        private readonly NotificationCategory $category,
        private readonly string $eventType,
        private readonly string $title,
        private readonly string $body,
        bool $isRead,
        private readonly DateTimeImmutable $createdAt,
        private readonly ?array $referenceData,
        private readonly ?array $parameters,
    ) {
        $this->isRead = $isRead;
    }

    /**
     * Create a new notification.
     */
    public static function create(
        Uuid $userId,
        NotificationCategory $category,
        string $eventType,
        string $title,
        string $body,
        ?array $referenceData = null,
        ?array $parameters = null,
    ): self {
        return new self(
            Uuid::generate(),
            new PublicId(),
            $userId,
            $category,
            $eventType,
            $title,
            $body,
            false,
            new DateTimeImmutable(),
            $referenceData,
            $parameters,
        );
    }

    /**
     * Reconstitute a Notification from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(
        Uuid $id,
        PublicId $publicId,
        Uuid $userId,
        NotificationCategory $category,
        string $eventType,
        string $title,
        string $body,
        bool $isRead,
        DateTimeImmutable $createdAt,
        ?array $referenceData = null,
        ?array $parameters = null,
    ): self {
        return new self(
            $id,
            $publicId,
            $userId,
            $category,
            $eventType,
            $title,
            $body,
            $isRead,
            $createdAt,
            $referenceData,
            $parameters,
        );
    }

    public function markAsRead(): void
    {
        if ($this->isRead) {
            return;
        }

        $this->isRead = true;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->publicId;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getCategory(): NotificationCategory
    {
        return $this->category;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReferenceData(): ?array
    {
        return $this->referenceData;
    }

    public function getParameters(): ?array
    {
        return $this->parameters;
    }
}

<?php

declare(strict_types=1);

namespace App\Notification\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;

final readonly class NotificationEvent extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $eventClass,
        private readonly string $eventName,
        private readonly array $payload,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public function getEventClass(): string
    {
        return $this->eventClass;
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function eventName(): string
    {
        return 'notification.event';
    }
}

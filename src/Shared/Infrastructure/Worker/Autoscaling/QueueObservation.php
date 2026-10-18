<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker\Autoscaling;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class QueueObservation implements \JsonSerializable
{
    public function __construct(
        public string $state,
        public float $sampledAt,
        public ?int $unreadCount = null,
        public ?int $pendingCount = null,
        public ?int $dueCount = null,
        public ?int $futureCount = null,
        public ?float $oldestUnreadAgeSeconds = null,
        public ?float $oldestDueAgeSeconds = null,
        public ?string $fault = null,
    ) {
    }

    public function isKnown(): bool
    {
        return $this->state === 'known' && $this->unreadCount !== null && $this->pendingCount !== null
            && $this->dueCount !== null && $this->futureCount !== null;
    }

    /** @return array<string, int|float|string|null> */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
